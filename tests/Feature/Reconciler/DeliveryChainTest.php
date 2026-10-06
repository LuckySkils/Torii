<?php

declare(strict_types=1);

use App\Enums\DeliveryState;
use App\Enums\FixAttempted;
use App\Enums\NotificationKind;
use App\Events\ReleaseDownloaded;
use App\Http\Resources\ReleaseResource;
use App\Jobs\Reconciler\CheckDeliveryInJellyfin;
use App\Jobs\Reconciler\CheckSeriesEpisodes;
use App\Jobs\Reconciler\FinishLibraryRefresh;
use App\Jobs\Reconciler\ProcessReconcilerEvent;
use App\Jobs\SendNotification;
use App\Models\Delivery;
use App\Models\NotificationLog;
use App\Models\ReconcilerEvent;
use App\Models\Release;
use App\Services\Reconciler\DeliveryReconciler;
use App\Services\Reconciler\EventRecorder;
use App\Services\Reconciler\JellyfinClient;
use App\Services\Reconciler\JellyfinEvents;
use App\Services\Reconciler\ShokoEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const PSYREN = '[SubsPlease] PSYREN - 01 (1080p) [EB85F891].mkv';

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake();
    $GLOBALS['reconcilerRan'] = [];
    config([
        'subtracker.reconciler.enabled' => true,
        'subtracker.reconciler.dry_run' => false,
        'subtracker.reconciler.jellyfin_url' => 'http://jellyfin.test',
        'subtracker.reconciler.jellyfin_api_key' => 'jf-key',
        'subtracker.reconciler.jellyfin_anime_library_id' => ANIME_LIBRARY,
        'subtracker.reconciler.backoff' => [3, 7, 15],
        'subtracker.reconciler.library_refresh_timeout' => 300,
        'subtracker.reconciler.notify_on_fix' => true,
        'subtracker.notifications.enabled' => true,
    ]);
});

/** A downloaded release, as qbit:check-completed would announce it. */
function downloaded(string $title = PSYREN): Release
{
    $show = metadataShow(str($title)->between('] ', ' - ')->toString());
    $release = Release::create([
        'show_id' => $show->id, 'guid' => 'guid-'.md5($title), 'title' => $title, 'episode' => '01', 'is_batch' => false,
        'resolution' => '1080p', 'link' => 'magnet:?x', 'published_at' => now(), 'first_seen_at' => now(), 'downloaded_at' => now(),
    ]);
    ReleaseDownloaded::dispatch($release);

    return $release;
}

/** What the listener does with one captured message. */
function listen(string $source, array $message): void
{
    $event = $source === 'shoko'
        ? ShokoEvents::normalize($message['target'], $message['arguments'])
        : JellyfinEvents::normalize($message, ANIME_LIBRARY);

    if ($event !== null) {
        app(EventRecorder::class)->record($source, $event['type'], $message['target'] ?? 'LibraryChanged', $event['payload']);
    }
}

/**
 * Runs the queued jobs, and the jobs they queue, until none are left. Delays are
 * skipped; fix A's timeout safety net and notifications are left queued unless named.
 *
 * @param  array<int, class-string>  $alsoRun
 */
function drain(array $alsoRun = []): void
{
    do {
        $ran = false;

        foreach (Queue::pushedJobs() as $class => $entries) {
            if (in_array($class, [FinishLibraryRefresh::class, SendNotification::class], true) && ! in_array($class, $alsoRun, true)) {
                continue;
            }

            foreach ($entries as $i => $entry) {
                if (isset($GLOBALS['reconcilerRan']["{$class}#{$i}"])) {
                    continue;
                }

                $GLOBALS['reconcilerRan']["{$class}#{$i}"] = true;
                app()->call([$entry['job'], 'handle']);
                $ran = true;
            }
        }
    } while ($ran);
}

function replayPsyrenShoko(): void
{
    foreach (shokoCapture() as $message) {
        listen('shoko', $message);
    }

    drain();
}

function replayLibraryChanged(): void
{
    listen('jellyfin', jellyfinCapture()[2]);
    drain();
}

/**
 * @return array<string, mixed>
 */
function recentEpisodes(bool $withPsyren, string $seriesId = 'series-1'): array
{
    return ['Items' => $withPsyren ? [
        ['Id' => 'other', 'SeriesId' => 'x', 'ProviderIds' => ['Shoko File' => '2552', 'AniDB' => '1']],
        ['Id' => 'episode-1', 'SeriesId' => $seriesId, 'ProviderIds' => ['Shoko File' => '2553', 'Shoko Series' => '230']],
    ] : [['Id' => 'other', 'SeriesId' => 'x', 'ProviderIds' => ['Shoko File' => '2552']]], 'TotalRecordCount' => $withPsyren ? 2 : 1];
}

/**
 * @return array<string, mixed>
 */
function seriesEpisodes(int $count): array
{
    return ['Items' => array_fill(0, $count, ['Id' => 'e']), 'TotalRecordCount' => $count];
}

/**
 * @param  array<int, bool>  $recent  per lookup: is the Psyren episode in the recently added list
 * @param  array<int, int>  $episodes  per series check: how many episodes it lists
 */
function fakeJellyfin(array $recent, array $episodes = [4]): void
{
    $lookups = Http::sequence();
    foreach ($recent as $found) {
        $lookups->push(recentEpisodes($found));
    }
    $lookups->whenEmpty(Http::response(recentEpisodes(end($recent))));

    $lists = Http::sequence();
    foreach ($episodes as $count) {
        $lists->push(seriesEpisodes($count));
    }
    $lists->whenEmpty(Http::response(seriesEpisodes(end($episodes))));

    Http::fake([
        'http://jellyfin.test/Items?*' => $lookups,
        'http://jellyfin.test/Shows/*' => $lists,
        'http://jellyfin.test/Items/*' => Http::response('', 204),
    ]);
}

/** The reconciler's own notifications (the ordinary "downloaded" one is the release's). */
function assertNoDeliveryNotification(): void
{
    Queue::assertNotPushed(SendNotification::class, fn ($job) => in_array($job->kind, [NotificationKind::DeliveryFixed, NotificationKind::DeliveryGaveUp], true));
}

function psyrenDelivery(): Delivery
{
    return Delivery::where('filename', PSYREN)->firstOrFail();
}

/**
 * @return array<int, string>
 */
function jellyfinWrites(): array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => $pair[0]->method() === 'POST')
        ->map(fn (array $pair) => $pair[0]->url())
        ->values()
        ->all();
}

// ── Correlation ──────────────────────────────────────────────────────────────

test('a delivery is created when Torii marks a single episode downloaded, not for batches or with the reconciler off', function () {
    $release = downloaded();
    expect($release->delivery)->filename->toBe(PSYREN)->state->toBe(DeliveryState::Downloaded);

    $batch = Release::create([
        'guid' => 'b', 'title' => '[SubsPlease] PSYREN (01-12) (1080p) [Batch]', 'is_batch' => true, 'resolution' => '1080p',
        'link' => 'magnet:?b', 'published_at' => now(), 'first_seen_at' => now(),
    ]);
    ReleaseDownloaded::dispatch($batch);
    config(['subtracker.reconciler.enabled' => false]);
    $off = downloaded('[SubsPlease] Other - 01 (1080p) [AAAAAAAA].mkv');

    expect(Delivery::count())->toBe(1)
        ->and($off->fresh()->delivery)->toBeNull();
});

test('file.matched correlates by file name; a new show waits for its series, mapped through AniDB', function () {
    downloaded();
    $capture = shokoCapture();

    listen('shoko', $capture[2]); // FileMatched
    drain();
    expect(psyrenDelivery())->toMatchArray([
        'shoko_file_id' => 2553, 'anidb_anime_id' => 19765, 'shoko_series_id' => null, 'is_new_show' => true,
    ])->state->toBe(DeliveryState::AwaitingSeries)
        ->matched_at->not->toBeNull();

    listen('shoko', $capture[4]); // SeriesUpdated Added, Source AniDB
    drain();
    expect(psyrenDelivery()->shoko_series_id)->toBe(230);
    Queue::assertNotPushed(CheckDeliveryInJellyfin::class);

    listen('shoko', $capture[6]); // SeriesUpdated Added, Source Shoko: check A's trigger
    Queue::assertPushed(ProcessReconcilerEvent::class, 3);
    drain();
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->phase === DeliveryReconciler::INITIAL && $job->attempt === 1);
})->defer(fn () => fakeJellyfin([true]));

test('the series additions work in either order', function () {
    fakeJellyfin([true]);
    downloaded();
    $capture = shokoCapture();

    foreach ([$capture[2], $capture[6], $capture[4]] as $message) { // file, Shoko's series, then AniDB's
        listen('shoko', $message);
    }
    drain();

    expect(psyrenDelivery()->state)->toBe(DeliveryState::Playable);
});

test('files Torii didn\'t download are ignored', function () {
    listen('shoko', shokoCapture()[2]);
    drain();

    expect(Delivery::count())->toBe(0)
        ->and(ReconcilerEvent::first()->processed_at)->not->toBeNull();
});

test('an existing show\'s episode is never checked or fixed, but its trail advances on library.changed', function () {
    fakeJellyfin([true], [4]);
    downloaded();
    $matched = shokoCapture()[2];
    $matched['arguments'][0]['CrossReferences'][0]['SeriesID'] = 230; // the series already exists

    listen('shoko', $matched);
    listen('shoko', shokoCapture()[6]);
    drain();
    expect(psyrenDelivery())->is_new_show->toBeFalse()->state->toBe(DeliveryState::Matched);

    replayLibraryChanged();

    expect(psyrenDelivery())->state->toBe(DeliveryState::Playable)
        ->fix_attempted->toBe(FixAttempted::None)
        ->jellyfin_episode_id->toBe('episode-1');
    Queue::assertNotPushed(CheckDeliveryInJellyfin::class);
    Queue::assertNotPushed(CheckSeriesEpisodes::class);
    expect(jellyfinWrites())->toBe([]);
});

// ── Replaying the Psyren sequence through each path ──────────────────────────

test('path 1: found on the first check, series healthy: playable, no fix', function () {
    fakeJellyfin([true], [4]);
    downloaded();

    replayPsyrenShoko();

    expect(psyrenDelivery())->state->toBe(DeliveryState::Playable)
        ->fix_attempted->toBe(FixAttempted::None)
        ->jellyfin_series_id->toBe('series-1')
        ->in_jellyfin_at->not->toBeNull()
        ->playable_at->not->toBeNull();
    expect(jellyfinWrites())->toBe([]);
    assertNoDeliveryNotification();
});

test('path 2: failure A, fix A, then check B healthy', function () {
    fakeJellyfin([false, false, false, true], [4]);
    $release = downloaded();

    replayPsyrenShoko();
    expect(psyrenDelivery())->state->toBe(DeliveryState::FixingA)->fix_attempted->toBe(FixAttempted::A)
        ->and(jellyfinWrites())->toBe(['http://jellyfin.test/Items/'.ANIME_LIBRARY.'/Refresh?Recursive=true&MetadataRefreshMode=Default&ImageRefreshMode=Default&ReplaceAllMetadata=false']);

    replayLibraryChanged(); // the refresh finished

    expect(psyrenDelivery())->state->toBe(DeliveryState::Playable)->fix_attempted->toBe(FixAttempted::A);
    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::DeliveryFixed && $job->releaseId === $release->id);
});

test('path 3 (Nia Liston): failure A, fix A, check B broken, fix B, playable', function () {
    fakeJellyfin([false, false, false, true], [0, 0, 0, 4]);
    downloaded();

    replayPsyrenShoko();
    replayLibraryChanged();

    expect(psyrenDelivery())->state->toBe(DeliveryState::Playable)->fix_attempted->toBe(FixAttempted::AThenB)
        ->and(jellyfinWrites())->toBe([
            'http://jellyfin.test/Items/'.ANIME_LIBRARY.'/Refresh?Recursive=true&MetadataRefreshMode=Default&ImageRefreshMode=Default&ReplaceAllMetadata=false',
            'http://jellyfin.test/Items/series-1/Refresh?Recursive=true&MetadataRefreshMode=FullRefresh&ImageRefreshMode=Default&ReplaceAllMetadata=true',
        ]);
    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::DeliveryFixed);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'MediaBrowser Token="jf-key"'));
});

test('path 4 (Kashita): straight to failure B, fix B, playable', function () {
    fakeJellyfin([true], [0, 0, 0, 4]);
    downloaded();

    replayPsyrenShoko();

    expect(psyrenDelivery())->state->toBe(DeliveryState::Playable)->fix_attempted->toBe(FixAttempted::B)
        ->and(jellyfinWrites())->toBe(['http://jellyfin.test/Items/series-1/Refresh?Recursive=true&MetadataRefreshMode=FullRefresh&ImageRefreshMode=Default&ReplaceAllMetadata=true']);
});

test('path 5: fix B doesn\'t help: gave up, with a notification', function () {
    fakeJellyfin([true], [0]);
    $release = downloaded();

    replayPsyrenShoko();

    expect(psyrenDelivery())->state->toBe(DeliveryState::GaveUp)->fix_attempted->toBe(FixAttempted::B)
        ->last_error->toBe('Failure B: the series still lists no episodes after the series refresh.')
        ->gave_up_at->not->toBeNull()
        ->and(jellyfinWrites())->toHaveCount(1);
    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::DeliveryGaveUp && $job->releaseId === $release->id);
    Queue::assertPushed(CheckSeriesEpisodes::class, 6); // three before the fix, three after: never again
});

test('the gave-up notification says what failed, what was tried, and that a scan is needed', function () {
    fakeJellyfin([true], [0]);
    $release = downloaded();
    replayPsyrenShoko();

    Http::fake(['*' => Http::response('', 200)]);
    config(['subtracker.notifications.ntfy_url' => 'http://ntfy.test', 'subtracker.notifications.ntfy_topic' => 'torii']);
    app()->call([new SendNotification(NotificationKind::DeliveryGaveUp, $release->id), 'handle']);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'ntfy.test')
        && str_contains($request->body(), 'Failure B')
        && str_contains($request->body(), 'fix B (series refresh)')
        && str_contains($request->body(), 'Scan All Libraries'));
});

// ── Backoff ──────────────────────────────────────────────────────────────────

test('checks run 3, 7 and 15 s after the trigger; a success on attempt 2 stops the rest', function () {
    fakeJellyfin([false, true], [4]);
    downloaded();
    foreach (array_slice(shokoCapture(), 0, 3) as $message) {
        listen('shoko', $message);
    }
    drain();
    $delivery = psyrenDelivery()->fill(['shoko_series_id' => 230]);
    $delivery->save();
    $reconciler = app(DeliveryReconciler::class);

    $reconciler->startCheckA($delivery, DeliveryReconciler::INITIAL);
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->attempt === 1 && $job->delay === 3);

    $reconciler->checkA($delivery->id, 1, DeliveryReconciler::INITIAL, 1);
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->attempt === 2 && $job->delay === 4);

    $reconciler->checkA($delivery->id, 1, DeliveryReconciler::INITIAL, 2);
    Queue::assertNotPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->attempt === 3);
    expect(psyrenDelivery()->state)->toBe(DeliveryState::InJellyfin);
    Queue::assertPushed(CheckSeriesEpisodes::class, fn ($job) => $job->attempt === 1 && $job->delay === 3);
});

test('the third attempt waits 8 s more, and only after it does a failure count', function () {
    fakeJellyfin([false], [4]);
    downloaded();
    $delivery = psyrenDelivery();
    $delivery->update(['shoko_file_id' => 2553, 'is_new_show' => true, 'state' => DeliveryState::AwaitingSeries]);
    $reconciler = app(DeliveryReconciler::class);

    $reconciler->checkA($delivery->id, 1, DeliveryReconciler::INITIAL, 2);
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->attempt === 3 && $job->delay === 8);
    expect(jellyfinWrites())->toBe([]);

    $reconciler->checkA($delivery->id, 1, DeliveryReconciler::INITIAL, 3);
    expect(jellyfinWrites())->toHaveCount(1)
        ->and(psyrenDelivery()->state)->toBe(DeliveryState::FixingA);
});

// ── Fix A batching ───────────────────────────────────────────────────────────

test('two failures during one refresh share it; the refresh ends on library.changed', function () {
    fakeJellyfin([false]);
    $reconciler = app(DeliveryReconciler::class);
    $first = downloaded();
    $second = downloaded('[SubsPlease] Nia Liston - 01 (1080p) [BBBBBBBB].mkv');
    foreach ([$first, $second] as $i => $release) {
        $release->delivery->update(['shoko_file_id' => 100 + $i, 'is_new_show' => true, 'state' => DeliveryState::AwaitingSeries]);
        $reconciler->checkA($release->delivery->id, 1, DeliveryReconciler::INITIAL, 3);
    }

    expect(jellyfinWrites())->toHaveCount(1)
        ->and(Delivery::where('state', DeliveryState::FixingA)->count())->toBe(2);
    Queue::assertPushed(FinishLibraryRefresh::class, 1);

    replayLibraryChanged();

    foreach ([$first, $second] as $release) {
        Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->deliveryId === $release->delivery->id && $job->phase === DeliveryReconciler::AFTER_FIX_A);
    }

    // A later failure starts a new refresh.
    $third = downloaded('[SubsPlease] Third - 01 (1080p) [CCCCCCCC].mkv');
    $third->delivery->update(['shoko_file_id' => 300, 'is_new_show' => true, 'state' => DeliveryState::AwaitingSeries]);
    $reconciler->checkA($third->delivery->id, 1, DeliveryReconciler::INITIAL, 3);
    expect(jellyfinWrites())->toHaveCount(2);
});

test('without a library.changed, the refresh is released by its timeout; a stale timeout does nothing', function () {
    fakeJellyfin([false]);
    $reconciler = app(DeliveryReconciler::class);
    $release = downloaded();
    $release->delivery->update(['shoko_file_id' => 2553, 'is_new_show' => true, 'state' => DeliveryState::AwaitingSeries]);
    $reconciler->checkA($release->delivery->id, 1, DeliveryReconciler::INITIAL, 3);

    $timeout = Queue::pushed(FinishLibraryRefresh::class)->first();
    expect($timeout->delay)->toBe(300);

    $reconciler->finishLibraryRefresh('some-other-token');
    Queue::assertNotPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->phase === DeliveryReconciler::AFTER_FIX_A);

    app()->call([$timeout, 'handle']);
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->phase === DeliveryReconciler::AFTER_FIX_A);
});

test('a refresh that still doesn\'t bring the episode gives up as failure A', function () {
    fakeJellyfin([false]);
    downloaded();

    replayPsyrenShoko();
    replayLibraryChanged();

    expect(psyrenDelivery())->state->toBe(DeliveryState::GaveUp)
        ->last_error->toBe('Failure A: the episode never reached Jellyfin, even after the Anime library refresh.');
    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::DeliveryGaveUp);
});

// ── Dry run ──────────────────────────────────────────────────────────────────

test('dry run: the whole chain runs, no write is sent, and the intended fixes are recorded', function () {
    config(['subtracker.reconciler.dry_run' => true]);
    fakeJellyfin([false, false, false, true], [0]);
    downloaded();

    replayPsyrenShoko();
    replayLibraryChanged();

    expect(jellyfinWrites())->toBe([])
        ->and(psyrenDelivery())->would_have_fixed->toBe('fix A (refresh the Anime library), then fix B (refresh series series-1)')
        ->state->toBe(DeliveryState::GaveUp)
        ->last_error->toEndWith('(Dry run: no fix was sent.)');
    assertNoDeliveryNotification();
});

test('dry run is also enforced in the Jellyfin client itself', function () {
    config(['subtracker.reconciler.dry_run' => true]);

    expect(fn () => app(JellyfinClient::class)->refreshAnimeLibrary())->toThrow(LogicException::class);
    expect(jellyfinWrites())->toBe([]);
});

test('dry run is the default: only an explicit false value turns it off', function (?string $value, bool $dry) {
    $value === null ? putenv('RECONCILER_DRY_RUN') : putenv("RECONCILER_DRY_RUN={$value}");
    $_ENV['RECONCILER_DRY_RUN'] = $_SERVER['RECONCILER_DRY_RUN'] = $value;
    if ($value === null) {
        unset($_ENV['RECONCILER_DRY_RUN'], $_SERVER['RECONCILER_DRY_RUN']);
    }

    try {
        expect((require config_path('subtracker.php'))['reconciler']['dry_run'])->toBe($dry);
    } finally {
        putenv('RECONCILER_DRY_RUN');
        unset($_ENV['RECONCILER_DRY_RUN'], $_SERVER['RECONCILER_DRY_RUN']);
    }
})->with([
    'absent' => [null, true],
    'empty' => ['', true],
    'typo' => ['flase', true],
    'true' => ['true', true],
    'false' => ['false', false],
    'zero' => ['0', false],
]);

// ── Repair and props ─────────────────────────────────────────────────────────

test('repair re-runs the chain from the start, ignoring jobs from the earlier run', function () {
    fakeJellyfin([true], [0]);
    $release = downloaded();
    replayPsyrenShoko();
    NotificationLog::create(['kind' => NotificationKind::DeliveryGaveUp, 'release_id' => $release->id, 'status' => 'sent', 'sent_at' => now()]);
    expect(psyrenDelivery()->state)->toBe(DeliveryState::GaveUp);

    $this->post(route('deliveries.repair', psyrenDelivery()))->assertRedirect();

    expect(psyrenDelivery())->run->toBe(2)->state->toBe(DeliveryState::AwaitingSeries)
        ->fix_attempted->toBe(FixAttempted::None)->gave_up_at->toBeNull()->last_error->toBeNull()
        ->and(NotificationLog::count())->toBe(0);
    Queue::assertPushed(CheckDeliveryInJellyfin::class, fn ($job) => $job->run === 2 && $job->attempt === 1);

    // A leftover job from run 1 does nothing.
    app(DeliveryReconciler::class)->checkA(psyrenDelivery()->id, 1, DeliveryReconciler::INITIAL, 1);
    expect(psyrenDelivery()->state)->toBe(DeliveryState::AwaitingSeries);
});

test('releases carry their delivery for the frontend, or null', function () {
    fakeJellyfin([true], [0, 0, 0, 4]);
    $release = downloaded();
    replayPsyrenShoko();

    $props = (new ReleaseResource($release->fresh('delivery')))->resolve()['delivery'];

    expect($props)->toMatchArray(['state' => 'playable', 'isNewShow' => true, 'fixAttempted' => 'b', 'wouldHaveFixed' => null, 'lastError' => null])
        ->and(array_keys($props['stages']))->toBe(['matchedAt', 'inJellyfinAt', 'playableAt', 'gaveUpAt'])
        ->and($props['stages']['gaveUpAt'])->toBeNull();

    config(['subtracker.reconciler.enabled' => false]);
    expect((new ReleaseResource(downloaded('[SubsPlease] Plain - 01 (1080p) [DDDDDDDD].mkv')))->resolve()['delivery'])->toBeNull();
});

test('recorded events are pruned after 14 days', function () {
    ReconcilerEvent::create(['source' => 'shoko', 'type' => 'file.matched', 'raw_target' => 'x', 'payload' => [], 'received_at' => now()->subDays(15)]);
    ReconcilerEvent::create(['source' => 'shoko', 'type' => 'file.matched', 'raw_target' => 'x', 'payload' => [], 'received_at' => now()->subDays(13)]);

    $this->artisan('model:prune', ['--model' => [ReconcilerEvent::class]])->assertSuccessful();

    expect(ReconcilerEvent::count())->toBe(1);
});
