<?php

declare(strict_types=1);

use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Models\AnimeImage;
use App\Models\AnimePayload;
use App\Models\ShowAnimeLink;
use App\Services\Bootstrap\BootstrapTasks;
use App\Services\Metadata\CoverUrls;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-29 12:00:00');
});

/**
 * A PNG header getimagesizefromstring reads as $width x $height (no GD needed),
 * padded so different sizes hash differently.
 */
function pngOf(int $width, int $height, string $padding = ''): string
{
    $ihdr = pack('NN', $width, $height)."\x08\x02\x00\x00\x00";

    return "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).$padding;
}

const LARGE_URL = 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/medium/bx1-abc.jpg';
const EXTRA_LARGE_URL = 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/large/bx1-abc.jpg';

function storedCover(Anime $anime, int $width, int $height, string $url): AnimeImage
{
    $bytes = pngOf($width, $height);

    return AnimeImage::create([
        'anime_id' => $anime->id, 'source_url' => $url, 'mime' => 'image/png', 'data' => base64_encode($bytes),
        'size' => strlen($bytes), 'width' => $width, 'height' => $height, 'sha256' => hash('sha256', $bytes), 'fetched_at' => now()->subWeek(),
    ]);
}

/**
 * @return array<int, int> seconds from now of each queued cover fetch, in dispatch order
 */
function coverDelays(): array
{
    return Queue::pushed(FetchAnimeCover::class)
        ->map(fn (FetchAnimeCover $job) => $job->delay === null ? 0 : (int) now()->diffInSeconds($job->delay))
        ->values()
        ->all();
}

// ── The largest size, and upgrading ──────────────────────────────────────────

test('CoverUrls points every cover_url at the payload\'s extraLarge, without requests, idempotently', function () {
    $anime = metadataAnime(['cover_url' => LARGE_URL]);
    AnimePayload::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'fetched_at' => now(), 'payload' => ['coverImage' => ['extraLarge' => EXTRA_LARGE_URL, 'large' => LARGE_URL]]]);
    $noPayload = metadataAnime(['cover_url' => LARGE_URL]);

    expect(app(CoverUrls::class)->useLargest())->toBe(1)
        ->and($anime->fresh()->cover_url)->toBe(EXTRA_LARGE_URL)
        ->and($noPayload->fresh()->cover_url)->toBe(LARGE_URL)
        ->and(app(CoverUrls::class)->useLargest())->toBe(0);

    Http::assertNothingSent();
});

test('--upgrade fetches only covers stored from another URL (the old size)', function () {
    Queue::fake();
    $old = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    storedCover($old, 230, 321, LARGE_URL);
    $current = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    storedCover($current, 460, 650, EXTRA_LARGE_URL);
    metadataAnime(['cover_url' => EXTRA_LARGE_URL]); // missing: not an upgrade

    $this->artisan('anime:fetch-images --upgrade')->expectsOutputToContain('for 1 anime stored at an old size')->assertSuccessful();

    Queue::assertPushed(FetchAnimeCover::class, 1);
    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $old->id);
});

test('--all covers every anime with a cover URL', function () {
    Queue::fake();
    storedCover(metadataAnime(['cover_url' => EXTRA_LARGE_URL]), 460, 650, EXTRA_LARGE_URL);
    metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    metadataAnime(['cover_url' => null]);

    $this->artisan('anime:fetch-images --all')->expectsOutputToContain('for 2 anime in the database')->assertSuccessful();
});

test('an upgrade replaces the stored cover when the sha256 differs; size and props are whatever the artwork is', function (int $width, int $height) {
    // extraLarge is the source artwork: its dimensions vary per title (the old size was ~230x321).
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL, 'season' => 'SUMMER', 'season_year' => 2026]);
    storedCover($anime, 230, 321, LARGE_URL);
    $artwork = pngOf($width, $height, 'artwork');
    Http::fake(['s4.anilist.co/*' => Http::response($artwork, 200, ['Content-Type' => 'image/png'])]);

    (new FetchAnimeCover($anime->id))->handle();

    $image = AnimeImage::where('anime_id', $anime->id)->sole();

    expect($image->width)->toBe($width)
        ->and($image->height)->toBe($height)
        ->and($image->source_url)->toBe(EXTRA_LARGE_URL)
        ->and($image->sha256)->toBe(hash('sha256', $artwork));

    $this->withoutVite()->get('/anime')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('anime.data.0.coverWidth', $width)
        ->where('anime.data.0.coverHeight', $height)
        ->where('anime.data.0.coverUrl', "/anime/{$anime->id}/cover?v=".substr($image->sha256, 0, 8)));
})->with([
    'portrait, twice the old size' => [460, 650],
    'taller artwork' => [425, 600],
    'large source' => [700, 1000],
]);

test('same bytes from the new URL keep the row, but record the URL so --upgrade stops picking it', function () {
    Queue::fake();
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    $stored = storedCover($anime, 460, 650, LARGE_URL);
    Http::fake(['s4.anilist.co/*' => Http::response(pngOf(460, 650), 200, ['Content-Type' => 'image/png'])]);

    (new FetchAnimeCover($anime->id))->handle();

    expect($stored->fresh()->source_url)->toBe(EXTRA_LARGE_URL)
        ->and($stored->fresh()->fetched_at)->toEqual($stored->fetched_at);

    $this->artisan('anime:fetch-images --upgrade')->expectsOutputToContain('for 0 anime');
});

// ── Spacing and deduplication ────────────────────────────────────────────────

test('cover fetches from any source are spaced 2 seconds apart on one channel, the first immediate', function () {
    Queue::fake();
    foreach (range(1, 4) as $i) {
        metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    }

    // A bulk command, then a page view's request, share the one timeline.
    $this->artisan('anime:fetch-images');
    FetchAnimeCover::dispatchSpaced(metadataAnime(['cover_url' => EXTRA_LARGE_URL])->id);

    expect(coverDelays())->toBe([0, 2, 4, 6, 8]);
});

test('a cover already queued is not queued again, and the duplicate takes no slot', function () {
    Queue::fake();
    $a = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    $b = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);

    expect(FetchAnimeCover::dispatchSpaced($a->id))->toBeTrue()
        ->and(FetchAnimeCover::dispatchSpaced($a->id))->toBeFalse()
        ->and(FetchAnimeCover::dispatchSpaced($b->id))->toBeTrue()
        ->and(coverDelays())->toBe([0, 2]);
});

// ── Failures ─────────────────────────────────────────────────────────────────

test('a cover that fails for good is recorded on the anime; a later success clears it', function () {
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);
    Http::fake(['s4.anilist.co/*' => Http::sequence()
        ->push('gone', 404, ['Content-Type' => 'text/html'])
        ->push(pngOf(460, 650), 200, ['Content-Type' => 'image/png'])]);

    (new FetchAnimeCover($anime->id))->handle();

    expect($anime->fresh()->cover_error)->toContain('status 404')
        ->and($anime->fresh()->cover_error_at)->not->toBeNull();

    (new FetchAnimeCover($anime->id))->handle();

    expect($anime->fresh()->cover_error)->toBeNull()
        ->and($anime->fresh()->cover_error_at)->toBeNull()
        ->and(AnimeImage::where('anime_id', $anime->id)->exists())->toBeTrue();
});

test('a job that runs out of tries records the error too', function () {
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);

    (new FetchAnimeCover($anime->id))->failed(new ConnectionException('CDN unreachable'));

    expect($anime->fresh()->cover_error)->toBe('CDN unreachable');
});

// ── On demand ────────────────────────────────────────────────────────────────

test('the index queues covers for shown entries without one, once, skipping failed and stored ones', function () {
    Queue::fake();
    $this->withoutVite();
    $fall = ['season' => 'FALL', 'season_year' => 2026, 'cover_url' => EXTRA_LARGE_URL];
    $missing = metadataAnime(['title_romaji' => 'A Missing', ...$fall]);
    $failed = metadataAnime(['title_romaji' => 'B Failed', 'cover_error' => '404', 'cover_error_at' => now(), ...$fall]);
    $stored = metadataAnime(['title_romaji' => 'C Stored', ...$fall]);
    storedCover($stored, 460, 650, EXTRA_LARGE_URL);
    metadataAnime(['title_romaji' => 'D No URL', ...$fall, 'cover_url' => null]);

    $this->get('/anime?season=FALL&year=2026')->assertOk();

    Queue::assertPushed(FetchAnimeCover::class, 1);
    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $missing->id);

    // Repeated views, even after the queued job finished and released its lock, don't re-queue.
    $this->get('/anime?season=FALL&year=2026');
    Queue::pushed(FetchAnimeCover::class)->each(fn ($job) => (new UniqueLock(app(Repository::class)))->release($job));
    $this->get('/anime?season=FALL&year=2026');

    Queue::assertPushed(FetchAnimeCover::class, 1);
    Queue::assertNotPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $failed->id);
});

test('a request 10 minutes later may queue again (the cover still being missing)', function () {
    Queue::fake();
    $this->withoutVite();
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);

    $this->get("/anime/{$anime->id}")->assertOk();
    Queue::pushed(FetchAnimeCover::class)->each(fn ($job) => (new UniqueLock(app(Repository::class)))->release($job));

    $this->travel(11)->minutes();
    $this->get("/anime/{$anime->id}")->assertOk();

    Queue::assertPushed(FetchAnimeCover::class, 2);
});

test('the detail page queues its own missing cover', function () {
    Queue::fake();
    $this->withoutVite();
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL]);

    $this->get("/anime/{$anime->id}")->assertOk();

    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $anime->id);
});

// ── Adult filter ─────────────────────────────────────────────────────────────

test('adult entries are hidden by default, and the filter can include or isolate them', function () {
    Queue::fake();
    $this->withoutVite();
    $fall = ['season' => 'FALL', 'season_year' => 2026];
    $general = metadataAnime(['title_romaji' => 'A General', ...$fall]);
    $adult = metadataAnime(['title_romaji' => 'B Adult', 'is_adult' => true, ...$fall]);

    $ids = fn (string $query) => collect($this->get('/anime?season=FALL&year=2026'.$query)->viewData('page')['props']['anime']['data'])->pluck('id')->all();

    expect($ids(''))->toBe([$general->id])
        ->and($ids('&adult=include'))->toBe([$general->id, $adult->id])
        ->and($ids('&adult=only'))->toBe([$adult->id])
        ->and($ids('&adult=nonsense'))->toBe([$general->id]);

    $this->get('/anime?adult=only')->assertInertia(fn (AssertableInertia $page) => $page->where('filters.adult', 'only'));
    $this->get('/anime')->assertInertia(fn (AssertableInertia $page) => $page->where('filters.adult', 'hide'));
});

// ── Bootstrap ────────────────────────────────────────────────────────────────

test('the full-covers bootstrap task fills in missing covers, then upgrades old-size ones', function () {
    Queue::fake();
    $task = collect(app(BootstrapTasks::class)->all())->firstWhere('key', 'anime.full-covers');

    $jobs = ($task->jobs)();

    expect($task->dependsOn)->toBe(['anime.initial-covers'])
        ->and(array_map(fn ($job) => [$job->command, $job->parameters], $jobs))->toBe([
            ['anime:fetch-images', ['--missing' => true]],
            ['anime:fetch-images', ['--upgrade' => true]],
        ]);
});

test('linked old-season anime still get covers, as before', function () {
    Queue::fake();
    $anime = metadataAnime(['cover_url' => EXTRA_LARGE_URL, 'season' => 'FALL', 'season_year' => 1999]);
    ShowAnimeLink::create(['show_id' => metadataShow('One Piece')->id, 'anime_id' => $anime->id, 'confidence' => 100, 'source' => 'manual', 'linked_at' => now()]);

    $this->artisan('anime:fetch-images --missing');

    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $anime->id);
});
