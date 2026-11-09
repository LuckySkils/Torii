<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\ReleaseSource;
use App\Enums\RuleState;
use App\Events\ShowDiscovered;
use App\Http\Resources\LatestReleaseResource;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NyaaHelpers.php';

/*
 * Import from any release group (§16): the three real non-SubsPlease titles the
 * owner gave, verbatim, plus SubsPlease items of the same series. Every other
 * title here is constructed in the same shapes (marked as such).
 */
const ERAI_01 = '[Erai-raws] Demons Crest - 01 [1080p AMZN WEBRip HEVC EAC3][MultiSub][B2E1DE46]';
const JUDAS_S02E06 = '[Judas] Black Clover - S02E06 [1080p][HEVC x265 10bit][Multi-Subs] (Weekly)';
const TOONSHUB_S01E06 = '[ToonsHub] TANK CHAIR S01E06 1080p AMZN WEB-DL DUAL DDP5.1 H.265 (TANK CHAIR: Sensha Isu, Dual-Audio, Multi-Subs)';

// Constructed in the same shapes.
const JUDAS_S02E07 = '[Judas] Black Clover - S02E07 [1080p][HEVC x265 10bit][Multi-Subs] (Weekly)';
const JUDAS_S02E06_V2 = '[Judas] Black Clover - S02E06v2 [1080p][HEVC x265 10bit][Multi-Subs] (Weekly)';
const SP_BLACK_CLOVER_06 = '[SubsPlease] Black Clover S2 - 06 (1080p) [AAAAAAAA].mkv';
const SP_DEMONS_CREST_01 = '[SubsPlease] Demons Crest - 01 (1080p) [BBBBBBBB].mkv';
const SP_DEMONS_CREST_02 = '[SubsPlease] Demons Crest - 02 (1080p) [CCCCCCCC].mkv';
const UNPARSED_TITLE = '[Erai-raws] Demons Crest [1080p][MultiSub]';

/** The owner's wording (§16): one generic reason for every untrackable show or new target. */
const BLOCKED_REASON = "No SubsPlease releases: Torii's download rule only follows SubsPlease's feed.";

beforeEach(function () {
    Http::preventStrayRequests();
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => '',
        'subtracker.qbittorrent.password' => '',
    ]);
});

/**
 * The mixed feed: three series from four groups, and one unparsed title.
 */
function mixedFeed(): void
{
    fakeNyaa([
        ['title' => ERAI_01, 'id' => 1, 'hash' => hashFor('erai-01'), 'seeders' => 900],
        ['title' => SP_DEMONS_CREST_01, 'id' => 2, 'hash' => hashFor('sp-dc-01'), 'seeders' => 100],
        ['title' => SP_DEMONS_CREST_02, 'id' => 3, 'hash' => hashFor('sp-dc-02')],
        ['title' => JUDAS_S02E06, 'id' => 4, 'hash' => hashFor('judas-06')],
        ['title' => JUDAS_S02E07, 'id' => 5, 'hash' => hashFor('judas-07')],
        ['title' => SP_BLACK_CLOVER_06, 'id' => 6, 'hash' => hashFor('sp-bc-06')],
        ['title' => TOONSHUB_S01E06, 'id' => 7, 'hash' => hashFor('toons-06')],
        ['title' => UNPARSED_TITLE, 'id' => 8, 'hash' => hashFor('unparsed')],
    ]);
}

/**
 * @param  array<string, mixed>  $preview
 * @return array<string, array<string, mixed>>
 */
function seriesByName(array $preview): array
{
    return collect($preview['series'])->keyBy('suggestedName')->all();
}

/**
 * @param  array<string, mixed>  $overrides
 */
function heldRelease(Show $show, ?string $episode, array $overrides = []): Release
{
    static $n = 0;
    $n++;

    return Release::create([
        'show_id' => $show->id, 'guid' => "held-{$n}", 'title' => "held {$n}", 'episode' => $episode, 'is_batch' => false,
        'resolution' => '1080p', 'link' => 'magnet:?xt=urn:btih:'.sha1("held-{$n}"), 'published_at' => now(), 'first_seen_at' => now(),
        'release_group' => 'SubsPlease', ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $target
 */
function retarget(array $preview, string $seriesKey, array $target): TestResponse
{
    return test()->postJson("/import/nyaa/preview/{$preview['previewId']}/target", ['seriesKey' => $seriesKey, 'target' => $target]);
}

// ── Series ───────────────────────────────────────────────────────────────────

test('preview: items from several groups are grouped into series by their suggested show name', function () {
    mixedFeed();

    $preview = nyaaPreview();
    $series = seriesByName($preview);
    $items = collect($preview['items'])->keyBy('key');

    expect(array_keys($series))->toBe(['Black Clover S2', 'Demons Crest', 'TANK CHAIR'])
        ->and($series['Black Clover S2'])->toMatchArray([
            'key' => 'blackclover|s2', 'parsedName' => 'Black Clover S2', 'season' => 2, 'suggestedName' => 'Black Clover S2',
            'altTitles' => [], 'groups' => ['SubsPlease', 'Judas'], 'itemKeys' => ['4', '5', '6'],
            'target' => ['kind' => 'new', 'name' => 'Black Clover S2', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null], 'candidates' => [],
        ])
        ->and($series['Demons Crest'])->toMatchArray([
            'key' => 'demonscrest|s1', 'season' => null, 'groups' => ['SubsPlease', 'Erai-raws'], 'itemKeys' => ['1', '2', '3'],
        ])
        ->and($series['TANK CHAIR'])->toMatchArray([
            'key' => 'tankchair|s1', 'parsedName' => 'TANK CHAIR', 'season' => 1, 'altTitles' => ['TANK CHAIR: Sensha Isu', 'Sensha Isu'],
            'groups' => ['ToonsHub'], 'target' => [
                'kind' => 'new', 'name' => 'TANK CHAIR', 'trackable' => false,
                'trackBlockedReason' => BLOCKED_REASON, 'anime' => null,
            ],
        ]);

    // The parse result is on every item, for checking before confirming.
    expect($items['4'])->toMatchArray([
        'group' => 'Judas', 'showName' => 'Black Clover S2', 'parsedName' => 'Black Clover', 'season' => 2, 'seriesKey' => 'blackclover|s2',
        'episode' => '06', 'resolution' => '1080p',
    ])->and($items['7'])->toMatchArray(['group' => 'ToonsHub', 'parsedName' => 'TANK CHAIR', 'season' => 1, 'episode' => '06'])
        ->and($items['1'])->toMatchArray(['group' => 'Erai-raws', 'parsedName' => 'Demons Crest', 'season' => null, 'episode' => '01'])
        ->and($items['8'])->toMatchArray(['state' => 'unparsed', 'selected' => false, 'seriesKey' => null, 'group' => 'Erai-raws'])
        ->and($items['1'])->not->toHaveKeys(['crc', 'altTitles', 'publishedAtRaw', 'inQbit', 'known', 'position']);

    // By series, SubsPlease first within an episode, unparsed last.
    expect(array_column($preview['items'], 'key'))->toBe(['6', '4', '5', '2', '1', '3', '7', '8']);
});

test('preview: the same episode and resolution from several groups: one is preselected (SubsPlease first), the others are duplicate_episode', function () {
    mixedFeed();

    $items = collect(nyaaPreview()['items'])->keyBy('key');
    $summary = fn (string $key) => [$items[$key]['state'], $items[$key]['selected'], $items[$key]['reason']];

    expect($summary('6'))->toBe(['new', true, null])
        ->and($summary('4'))->toBe(['duplicate_episode', false, 'Another copy of episode 06 is selected (SubsPlease).'])
        ->and($summary('5'))->toBe(['new', true, null])
        // SubsPlease wins over Erai-raws' more-seeded copy.
        ->and($summary('2'))->toBe(['new', true, null])
        ->and($summary('1'))->toBe(['duplicate_episode', false, 'Another copy of episode 01 is selected (SubsPlease).'])
        ->and($summary('7'))->toBe(['new', true, null]);
});

test('preview: without SubsPlease, the trusted copy and then the most seeded one is kept', function () {
    // Constructed: Erai-raws and ToonsHub copies of the same Demons Crest episode.
    fakeNyaa([
        ['title' => ERAI_01, 'id' => 1, 'hash' => hashFor('erai'), 'seeders' => 50, 'trusted' => false],
        ['title' => '[ToonsHub] Demons Crest - 1 [1080p]', 'id' => 2, 'hash' => hashFor('toons'), 'seeders' => 10],
        ['title' => '[Judas] Demons Crest - 01 [1080p]', 'id' => 3, 'hash' => hashFor('judas'), 'seeders' => 40],
    ]);

    $items = collect(nyaaPreview()['items'])->keyBy('key');

    expect([$items['3']['state'], $items['2']['state'], $items['1']['state']])->toBe(['new', 'duplicate_episode', 'duplicate_episode'])
        ->and($items['2']['reason'])->toBe('Another copy of episode 1 is selected (Judas).');
});

test('preview: superseded is per group: a v2 replaces the same group\'s v1 only', function () {
    fakeNyaa([
        ['title' => JUDAS_S02E06, 'id' => 4, 'hash' => hashFor('judas-06')],
        ['title' => JUDAS_S02E06_V2, 'id' => 44, 'hash' => hashFor('judas-06v2')],
        ['title' => SP_BLACK_CLOVER_06, 'id' => 6, 'hash' => hashFor('sp-bc-06')],
    ]);

    $items = collect(nyaaPreview()['items'])->keyBy('key');

    expect([$items['4']['state'], $items['4']['reason']])->toBe(['superseded', 'v2 of this episode is also in the list.'])
        ->and($items['6']['state'])->toBe('new')
        ->and([$items['44']['state'], $items['44']['reason']])->toBe(['duplicate_episode', 'Another copy of episode 06 is selected (SubsPlease).']);
});

test('preview: a batch covers its series\' episodes from any group', function () {
    // Constructed: a Judas season batch next to the real Judas episode.
    fakeNyaa([
        ['title' => '[Judas] Black Clover - S02E01-E12 [1080p][Batch]', 'id' => 100, 'hash' => hashFor('batch')],
        ['title' => SP_BLACK_CLOVER_06, 'id' => 6, 'hash' => hashFor('sp-bc-06')],
    ]);

    $items = collect(nyaaPreview()['items'])->keyBy('key');

    expect($items['100'])->toMatchArray(['isBatch' => true, 'batchFrom' => 1, 'batchTo' => 12, 'selected' => true])
        ->and([$items['6']['selected'], $items['6']['reason']])->toBe([false, 'Covered by the batch of episodes 1-12 in this list.']);
});

// ── Suggested targets ────────────────────────────────────────────────────────

test('suggestion rules 1 and 2: an exact name, then a normalized name or alt title', function () {
    $exact = metadataShow('Black Clover S2');
    $sameName = metadataShow("Demon's Crest!");
    $alt = metadataShow('Sensha Isu');
    heldRelease($alt, '01');
    mixedFeed();

    $series = seriesByName(nyaaPreview());

    expect($series['Black Clover S2']['target'])->toBe(['kind' => 'existing', 'showId' => $exact->id, 'showName' => 'Black Clover S2', 'trackable' => false, 'trackBlockedReason' => BLOCKED_REASON, 'anime' => null])
        ->and($series['Black Clover S2']['candidates'])->toBe([
            ['kind' => 'show', 'showId' => $exact->id, 'showName' => 'Black Clover S2', 'why' => "Named exactly 'Black Clover S2'.", 'score' => 100, 'trackable' => false],
        ])
        ->and($series['Demons Crest']['target'])->toMatchArray(['kind' => 'existing', 'showId' => $sameName->id])
        ->and($series['Demons Crest']['candidates'][0]['why'])->toBe("Same name as 'Demons Crest', ignoring case, punctuation and season wording.")
        ->and($series['Demons Crest']['candidates'][0]['score'])->toBe(95)
        ->and($series['TANK CHAIR']['target'])->toBe(['kind' => 'existing', 'showId' => $alt->id, 'showName' => 'Sensha Isu', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null])
        ->and($series['TANK CHAIR']['candidates'][0]['why'])->toBe("Same name as the alt title 'Sensha Isu' in the release title.");
});

test('suggestion rule 3: a clear anime winner with a linked show is the target; runners-up are candidates', function () {
    $winner = mcpLinkedShow(metadataAnime(['title_romaji' => 'Sensha Isu', 'title_english' => 'Tank Chair: The Animation']), 'Tanku Cheaa');
    $runnerUp = mcpLinkedShow(metadataAnime(['title_romaji' => 'Sensha Isuu']), 'Other Tank');
    fakeNyaa([['title' => TOONSHUB_S01E06, 'id' => 7, 'hash' => hashFor('toons-06')]]);

    $series = nyaaPreview()['series'][0];

    expect($series['target'])->toBe(['kind' => 'existing', 'showId' => $winner->id, 'showName' => 'Tanku Cheaa', 'trackable' => false, 'trackBlockedReason' => BLOCKED_REASON, 'anime' => null])
        ->and($series['candidates'])->toBe([
            ['kind' => 'show', 'showId' => $winner->id, 'showName' => 'Tanku Cheaa', 'why' => "Anime title 'Sensha Isu' of the linked anime.", 'score' => 100, 'trackable' => false],
            ['kind' => 'show', 'showId' => $runnerUp->id, 'showName' => 'Other Tank', 'why' => "Anime title 'Sensha Isuu' of the linked anime.", 'score' => 71, 'trackable' => false],
        ]);
});

test('suggestion rule 3: close anime matches only become candidates; a rejected pair never offers its show, so that anime counts as unlinked', function () {
    $a = mcpLinkedShow(metadataAnime(['title_romaji' => 'Demons Crest']), 'Akuma no Monshou');
    $b = mcpLinkedShow(metadataAnime(['title_english' => 'Demons Crest']), 'Demons Crest Other');
    $rejectedAnime = metadataAnime(['title_romaji' => 'Demons Crest!']);
    $rejected = mcpLinkedShow($rejectedAnime, 'Rejected Show');
    ShowAnimeRejection::create(['show_id' => $rejected->id, 'anime_id' => $rejectedAnime->id, 'rejected_at' => now()]);
    fakeNyaa([['title' => ERAI_01, 'id' => 1, 'hash' => hashFor('erai-01')]]);

    $series = nyaaPreview()['series'][0];

    // Its only link rejected, "Demons Crest!" has no show for this purpose (as matching sees it): an anime candidate.
    expect($series['target'])->toBe(['kind' => 'new', 'name' => 'Demons Crest', 'trackable' => false, 'trackBlockedReason' => BLOCKED_REASON, 'anime' => null])
        ->and(array_column($series['candidates'], 'kind'))->toBe(['show', 'show', 'anime'])
        ->and(array_column($series['candidates'], 'showId'))->toBe([$a->id, $b->id])
        ->and($series['candidates'][2]['anime']['id'])->toBe($rejectedAnime->id)
        ->and($series['candidates'][2]['why'])->toBe("Anime title 'Demons Crest!', no show yet.")
        ->and(array_column($series['candidates'], 'score'))->toBe([100, 100, 100]);
});

function mcpLinkedShow(Anime $anime, string $name): Show
{
    $show = metadataShow($name);
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 95, 'source' => 'auto', 'linked_at' => now()]);

    return $show;
}

// ── have_episode ─────────────────────────────────────────────────────────────

test('have_episode: the target show already has that episode from any group (numeric compare, batches cover)', function () {
    $blackClover = metadataShow('Black Clover S2');
    heldRelease($blackClover, '6', ['downloaded_at' => now()]);
    $demonsCrest = metadataShow('Demons Crest');
    heldRelease($demonsCrest, null, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 1, 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'dispatch_status' => DispatchStatus::Sent]);
    heldRelease($demonsCrest, '02'); // not sent, not downloaded: doesn't count
    mixedFeed();

    $items = collect(nyaaPreview()['items'])->keyBy('key');
    $summary = fn (string $key) => [$items[$key]['state'], $items[$key]['selected'], $items[$key]['reason']];

    expect($summary('6'))->toBe(['have_episode', false, 'Already have episode 06 (SubsPlease, downloaded).'])
        ->and($summary('4'))->toBe(['have_episode', false, 'Already have episode 06 (SubsPlease, downloaded).'])
        ->and($summary('5'))->toBe(['new', true, null])
        ->and($summary('2'))->toBe(['have_episode', false, 'Already have episode 01 (Erai-raws batch 1-1, sent to qBittorrent).'])
        ->and($summary('3'))->toBe(['new', true, null]);
});

// ── Retarget ─────────────────────────────────────────────────────────────────

test('retarget: to an existing show, a new name, and a new name that already exists; states follow the target', function () {
    $other = metadataShow('Burakku Kurobaa S2');
    heldRelease($other, '06', ['downloaded_at' => now()]);
    mixedFeed();
    $preview = nyaaPreview();

    // An existing show: its episode 6 makes both copies have_episode.
    $updated = retarget($preview, 'blackclover|s2', ['showId' => $other->id])->assertOk()->json();
    $items = collect($updated['items'])->keyBy('key');

    expect(seriesByName($updated)['Black Clover S2']['target'])->toBe(['kind' => 'existing', 'showId' => $other->id, 'showName' => 'Burakku Kurobaa S2', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null])
        ->and([$items['6']['state'], $items['4']['state'], $items['5']['state']])->toBe(['have_episode', 'have_episode', 'new'])
        ->and($items['6'])->not->toHaveKey('inQbit')
        ->and($updated['previewId'])->toBe($preview['previewId']);

    // A new name (trimmed): a new target never has episodes.
    $updated = retarget($preview, 'blackclover|s2', ['newName' => '  Black Clover Season 2  '])->assertOk()->json();
    $items = collect($updated['items'])->keyBy('key');

    expect(seriesByName($updated)['Black Clover S2']['target'])->toBe(['kind' => 'new', 'name' => 'Black Clover Season 2', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null])
        ->and([$items['6']['state'], $items['4']['state']])->toBe(['new', 'duplicate_episode']);

    // A new name some show has exactly becomes that show.
    $updated = retarget($preview, 'blackclover|s2', ['newName' => 'Burakku Kurobaa S2'])->assertOk()->json();

    expect(seriesByName($updated)['Black Clover S2']['target'])->toMatchArray(['kind' => 'existing', 'showId' => $other->id]);
});

test('retarget: unknown preview, series or show and a bad name are 422s with a plain message', function () {
    mixedFeed();
    $preview = nyaaPreview();

    retarget($preview, 'nope|s1', ['showId' => 1])->assertStatus(422)->assertJsonPath('message', 'This preview has no series "nope|s1".');
    retarget($preview, 'tankchair|s1', ['showId' => 999999])->assertStatus(422)->assertJsonPath('message', 'There is no show with id 999999.');
    retarget($preview, 'tankchair|s1', ['newName' => '   '])->assertStatus(422);
    retarget($preview, 'tankchair|s1', ['newName' => str_repeat('x', 256)])->assertStatus(422)->assertJsonPath('message', 'A new show needs a name of 1 to 255 characters.');
    retarget($preview, 'tankchair|s1', [])->assertStatus(422);
    retarget($preview, 'tankchair|s1', ['showId' => 1, 'newName' => 'x'])->assertStatus(422);
    $this->postJson('/import/nyaa/preview/no-such-preview/target', ['seriesKey' => 'tankchair|s1', 'target' => ['newName' => 'x']])
        ->assertStatus(422)
        ->assertJsonPath('message', 'This preview has expired or was already used (previews last 15 minutes). Preview the link again.');
});

test('retarget has its own rate limit (default 60 a minute), separate from the preview\'s (10)', function () {
    expect(config('subtracker.nyaa.retargets_per_minute'))->toBe(60)
        ->and(config('subtracker.nyaa.previews_per_minute'))->toBe(10);

    config(['subtracker.nyaa.previews_per_minute' => 2, 'subtracker.nyaa.retargets_per_minute' => 2]);
    mixedFeed();
    $preview = nyaaPreview();

    retarget($preview, 'tankchair|s1', ['newName' => 'Tank Chair'])->assertOk();
    retarget($preview, 'tankchair|s1', ['newName' => 'Tank Chair'])->assertOk();
    retarget($preview, 'tankchair|s1', ['newName' => 'Tank Chair'])->assertTooManyRequests();

    // The retargets didn't use up the preview's own budget.
    nyaaPreview();
});

// ── Show search ──────────────────────────────────────────────────────────────

test('show search: every word in the name, exact name first, at most 20, with trackability', function () {
    $exact = metadataShow('Black Clover');
    heldRelease($exact, '01');
    $s2 = metadataShow('Black Clover S2', ['latest_episode' => '06']);
    $market = metadataShow('Clover Black Market');
    metadataShow('Black Lagoon');
    foreach (range(1, 25) as $i) {
        metadataShow("Many Show {$i}");
    }

    $shows = $this->getJson('/import/nyaa/shows?q=black%20CLOVER')->assertOk()->json('shows');

    expect($shows)->toBe([
        ['id' => $exact->id, 'name' => 'Black Clover', 'isTracked' => false, 'trackable' => true, 'latestEpisode' => null],
        ['id' => $s2->id, 'name' => 'Black Clover S2', 'isTracked' => false, 'trackable' => false, 'latestEpisode' => '06'],
        ['id' => $market->id, 'name' => 'Clover Black Market', 'isTracked' => false, 'trackable' => false, 'latestEpisode' => null],
    ])
        ->and($this->getJson('/import/nyaa/shows?q=many')->json('shows'))->toHaveCount(20)
        ->and($this->getJson('/import/nyaa/shows?q=')->json('shows'))->toBe([])
        ->and($this->getJson('/import/nyaa/shows?q=100%25')->json('shows'))->toBe([]);
});

// ── Confirm ──────────────────────────────────────────────────────────────────

test('confirm: items land on their series\' targets, a new show is created once, the group is stored', function () {
    Queue::fake();
    Event::fake([ShowDiscovered::class]);
    $sensha = metadataShow('Sensha Isu');
    mixedFeed();
    $preview = nyaaPreview();
    retarget($preview, 'blackclover|s2', ['newName' => 'Black Clover (2017) S2'])->assertOk();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['2', '3', '5', '7']])->assertOk()->json();

    $demonsCrest = Show::where('name', 'Demons Crest')->firstOrFail();
    $blackClover = Show::where('name', 'Black Clover (2017) S2')->firstOrFail();
    $release = fn (int $id) => Release::where('guid', "https://nyaa.si/view/{$id}")->firstOrFail();

    expect($result)->toMatchArray(['queued' => 4, 'skipped' => [], 'errors' => []])
        ->and(collect($result['shows'])->sortBy('name')->values()->all())->toBe([
            ['id' => $blackClover->id, 'name' => 'Black Clover (2017) S2', 'created' => true, 'linkedAnime' => null],
            ['id' => $demonsCrest->id, 'name' => 'Demons Crest', 'created' => true, 'linkedAnime' => null],
            ['id' => $sensha->id, 'name' => 'Sensha Isu', 'created' => false, 'linkedAnime' => null],
        ])
        ->and(Show::where('name', 'Demons Crest')->count())->toBe(1)
        ->and([$release(2)->show_id, $release(3)->show_id, $release(5)->show_id, $release(7)->show_id])->toBe([$demonsCrest->id, $demonsCrest->id, $blackClover->id, $sensha->id])
        ->and([$release(2)->release_group, $release(5)->release_group, $release(7)->release_group])->toBe(['SubsPlease', 'Judas', 'ToonsHub'])
        ->and($release(7)->source)->toBe(ReleaseSource::Nyaa)
        ->and([$release(7)->episode, $release(7)->resolution])->toBe(['06', '1080p'])
        ->and($blackClover->is_tracked)->toBeFalse();

    Queue::assertPushed(QueueReleases::class, 1);
    Queue::assertNotPushed(SyncShowRule::class);
    Event::assertDispatchedTimes(ShowDiscovered::class, 2);
});

test('confirm: have_episode is checked again; a show with the new name that appeared since is reused', function () {
    Queue::fake();
    $target = metadataShow('Black Clover S2');
    heldRelease($target, '05', ['downloaded_at' => now()]);
    fakeNyaa([
        ['title' => JUDAS_S02E06, 'id' => 4, 'hash' => hashFor('judas-06')],
        ['title' => JUDAS_S02E07, 'id' => 5, 'hash' => hashFor('judas-07')],
        ['title' => '[Judas] Black Clover - S02E05 [1080p]', 'id' => 55, 'hash' => hashFor('judas-05')], // constructed
        ['title' => TOONSHUB_S01E06, 'id' => 7, 'hash' => hashFor('toons-06')],
    ]);
    $preview = nyaaPreview();
    expect(collect($preview['items'])->firstWhere('key', '55')['state'])->toBe('have_episode');

    // Between preview and confirm: SubsPlease's episode 6 is downloaded, and a "TANK CHAIR" show appears.
    heldRelease($target, '06', ['downloaded_at' => now()]);
    $tankChair = metadataShow('TANK CHAIR');

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['4', '5', '55', '7']])->assertOk()->json();

    expect($result['queued'])->toBe(3)
        ->and($result['skipped'])->toBe([['key' => '4', 'title' => JUDAS_S02E06, 'reason' => 'Already have episode 06 (SubsPlease, downloaded).']])
        // Ticked although the preview said have_episode: a deliberate choice.
        ->and(Release::where('guid', 'https://nyaa.si/view/55')->value('show_id'))->toBe($target->id)
        ->and(Release::where('guid', 'https://nyaa.si/view/7')->value('show_id'))->toBe($tankChair->id)
        ->and(collect($result['shows'])->firstWhere('id', $tankChair->id)['created'])->toBeFalse()
        ->and(Show::where('name', 'TANK CHAIR')->count())->toBe(1);
});

// ── Trackability ─────────────────────────────────────────────────────────────

function eraiOnlyShow(string $name = 'Demons Crest', array $attributes = []): Show
{
    $show = metadataShow($name, ['rule_state' => RuleState::None, ...$attributes]);
    heldRelease($show, '01', ['release_group' => 'Erai-raws', 'source' => 'nyaa', 'title' => ERAI_01]);

    return $show;
}

test('trackability: shows without a SubsPlease release are not trackable, with the reason, everywhere a track switch is', function () {
    $erai = eraiOnlyShow();
    $subsPlease = metadataShow('Black Clover S2');
    heldRelease($subsPlease, '06', ['release_group' => null]); // a feed release from before release_group: still SubsPlease
    $anime = metadataAnime(['title_romaji' => 'Demons Crest']);
    ShowAnimeLink::create(['show_id' => $erai->id, 'anime_id' => $anime->id, 'confidence' => 95, 'source' => 'auto', 'linked_at' => now()]);
    AnimeAiring::create(['anime_id' => $anime->id, 'episode' => 2, 'airs_at' => now()->addDay(), 'is_estimate' => false, 'provider' => 'anilist']);

    expect($erai->trackability())->toBe(['trackable' => false, 'reason' => BLOCKED_REASON])
        ->and($subsPlease->trackability())->toBe(['trackable' => true, 'reason' => null]);

    $this->get('/shows')->assertInertia(fn ($page) => $page
        ->where('shows.data.0.name', 'Black Clover S2')->where('shows.data.0.trackable', true)->where('shows.data.0.trackBlockedReason', null)
        ->where('shows.data.1.name', 'Demons Crest')->where('shows.data.1.trackable', false)->where('shows.data.1.trackBlockedReason', BLOCKED_REASON));

    $this->get("/shows/{$erai->id}")->assertInertia(fn ($page) => $page
        ->where('show.trackable', false)->where('show.trackBlockedReason', BLOCKED_REASON)
        ->where('releases.0.group', 'Erai-raws'));

    // The dashboard's LatestRelease (the page itself needs qBittorrent faked; DashboardControllerTest covers it).
    $latest = (new LatestReleaseResource(Release::where('show_id', $erai->id)->with('show')->firstOrFail()))->resolve();
    expect($latest['group'])->toBe('Erai-raws')
        ->and($latest['show'])->toMatchArray(['trackable' => false, 'trackBlockedReason' => BLOCKED_REASON]);

    $this->get("/anime/{$anime->id}")->assertInertia(fn ($page) => $page
        ->where('linkedShows.0.trackable', false)->where('linkedShows.0.trackBlockedReason', BLOCKED_REASON));

    $this->getJson("/anime/{$anime->id}/card")->assertJsonPath('linkedShow.trackable', false)->assertJsonPath('linkedShow.trackBlockedReason', BLOCKED_REASON);

    $this->get('/schedule')->assertInertia(fn ($page) => $page
        ->where('airings.0.show.trackable', false)->where('airings.0.show.trackBlockedReason', BLOCKED_REASON));
});

test('trackability: the reason is one generic text, the same for every show', function () {
    expect(BLOCKED_REASON)->toBe(Show::BLOCKED_REASON)
        ->and(metadataShow('Empty')->trackability())->toBe(['trackable' => false, 'reason' => BLOCKED_REASON])
        ->and(eraiOnlyShow('Other')->trackability())->toBe(['trackable' => false, 'reason' => BLOCKED_REASON]);
});

test('trackability: tracking is refused with an error flash and nothing changes; bulk skips and says how many', function () {
    Queue::fake();
    $erai = eraiOnlyShow();
    $subsPlease = metadataShow('Black Clover S2', ['rule_state' => RuleState::None]);
    heldRelease($subsPlease, '06');

    $this->patch("/shows/{$erai->id}/track", ['tracked' => true])
        ->assertRedirect()
        ->assertSessionHas('error', "Can't track \"Demons Crest\". ".BLOCKED_REASON);

    expect($erai->fresh())->is_tracked->toBeFalse()->rule_state->toBe(RuleState::None);
    Queue::assertNothingPushed();

    $this->post('/shows/track-bulk', ['ids' => [$erai->id, $subsPlease->id], 'tracked' => true])
        ->assertRedirect()
        ->assertSessionHas('success', 'Tracking 1 shows. Skipped 1. '.BLOCKED_REASON);

    expect($erai->fresh()->is_tracked)->toBeFalse()
        ->and($subsPlease->fresh()->is_tracked)->toBeTrue();
});

test('trackability: a tracked show without SubsPlease releases stays trackable while tracked, and can be untracked', function () {
    Queue::fake();
    $show = eraiOnlyShow('Demons Crest', ['is_tracked' => true, 'tracking_mode' => 'rule', 'rule_state' => RuleState::Synced]);

    expect($show->trackability())->toBe(['trackable' => true, 'reason' => null]);
    $this->get("/shows/{$show->id}")->assertInertia(fn ($page) => $page->where('show.trackable', true)->where('show.trackBlockedReason', null));

    // Re-tracking a tracked show is allowed (it queues what's missing).
    $this->patch("/shows/{$show->id}/track", ['tracked' => true])->assertSessionHas('success');

    $this->patch("/shows/{$show->id}/track", ['tracked' => false])->assertSessionHas('success', 'Stopped tracking "Demons Crest".');

    expect($show->fresh()->is_tracked)->toBeFalse()
        ->and($show->fresh()->trackability()['trackable'])->toBeFalse();
});

test('trackability over MCP: list_shows and get_show carry it, track_show refuses with the reason', function () {
    config(['subtracker.mcp.allow_writes' => true]);
    Queue::fake();
    $erai = eraiOnlyShow();

    expect(mcpTool('list_shows')['results'][0])->toMatchArray(['id' => $erai->id, 'trackable' => false])
        ->and(mcpTool('get_show', ['show_id' => $erai->id]))->toMatchArray(['trackable' => false, 'trackBlockedReason' => BLOCKED_REASON])
        ->and(mcpToolError('track_show', ['show_id' => $erai->id]))->toBe("Can't track \"Demons Crest\". ".BLOCKED_REASON)
        ->and($erai->fresh()->is_tracked)->toBeFalse();
});

// ── Migration backfill ───────────────────────────────────────────────────────

test('the release_group backfill: feed rows are SubsPlease, Nyaa rows their parsed group, unparsed stay null', function () {
    $show = metadataShow('Anything');
    $feed = heldRelease($show, '01', ['release_group' => null]);
    $erai = heldRelease($show, '01', ['release_group' => null, 'source' => 'nyaa', 'title' => ERAI_01]);
    $sp = heldRelease($show, '02', ['release_group' => null, 'source' => 'nyaa', 'title' => SP_DEMONS_CREST_02]);
    $unparsed = heldRelease($show, null, ['release_group' => null, 'source' => 'nyaa', 'title' => 'just some words']);
    $kept = heldRelease($show, '03', ['release_group' => 'Judas', 'source' => 'nyaa', 'title' => ERAI_01]);

    (require database_path('migrations/2026_11_08_000000_add_release_group_to_releases_table.php'))->backfill();

    expect([$feed->fresh()->release_group, $erai->fresh()->release_group, $sp->fresh()->release_group, $unparsed->fresh()->release_group, $kept->fresh()->release_group])
        ->toBe(['SubsPlease', 'Erai-raws', 'SubsPlease', null, 'Judas']);
});

// ── Live nyaa.si titles (Erai-raws RSS, captured on the test stand 2026-11-08) ─

const ERAI_AO_ASHI_06_HEVC = '[Erai-raws] Ao Ashi Season 2 - 06 [1080p CR WEBRip HEVC AAC][MultiSub][1211C3AE]';
const ERAI_AO_ASHI_06_AVC = '[Erai-raws] Ao Ashi Season 2 - 06 [1080p CR WEB-DL AVC AAC][MultiSub][465C6C16]';
const ERAI_AO_NO_HAKO_05_HEVC = '[Erai-raws] Ao no Hako Season 2 - 05 [1080p NF WEBRip HEVC EAC3][MultiSub][EDB9FC02]';
const ERAI_HYOUKEN_05_HEVC = '[Erai-raws] Hyouken no Majutsushi ga Sekai o Suberu II - 05 [1080p CR WEBRip HEVC AAC][MultiSub][689980CD]';
const ERAI_HYOUKEN_05_AVC = '[Erai-raws] Hyouken no Majutsushi ga Sekai o Suberu II - 05 [1080p CR WEB-DL AVC AAC][MultiSub][E3D687FE]';

test('real title (Erai-raws, live nyaa.si 2026-11-08): one group\'s HEVC and AVC copies of an episode: exactly one is preselected', function () {
    fakeNyaa([
        ['title' => ERAI_AO_ASHI_06_HEVC, 'id' => 1, 'hash' => hashFor('hevc'), 'seeders' => 30],
        ['title' => ERAI_AO_ASHI_06_AVC, 'id' => 2, 'hash' => hashFor('avc'), 'seeders' => 30],
        // Constructed: a 720p copy is a different resolution, so it isn't a copy of the same file.
        ['title' => '[Erai-raws] Ao Ashi Season 2 - 06 [720p CR WEB-DL AVC AAC][MultiSub][ABCDEF01]', 'id' => 3, 'hash' => hashFor('720')],
    ]);

    $preview = nyaaPreview();
    $items = collect($preview['items'])->keyBy('key');

    expect([$items['1']['state'], $items['1']['selected'], $items['1']['reason']])->toBe(['new', true, null])
        ->and([$items['2']['state'], $items['2']['selected'], $items['2']['reason']])->toBe(['duplicate_episode', false, 'Another copy of episode 06 is selected (Erai-raws).'])
        ->and([$items['3']['state'], $items['3']['selected']])->toBe(['new', true])
        ->and($preview['series'][0])->toMatchArray(['suggestedName' => 'Ao Ashi S2', 'parsedName' => 'Ao Ashi', 'season' => 2]);
});

test('real title (Erai-raws, live nyaa.si 2026-11-08): with more seeders on the second copy, that one is preselected', function () {
    fakeNyaa([
        ['title' => ERAI_AO_ASHI_06_HEVC, 'id' => 1, 'hash' => hashFor('hevc'), 'seeders' => 10],
        ['title' => ERAI_AO_ASHI_06_AVC, 'id' => 2, 'hash' => hashFor('avc'), 'seeders' => 90],
    ]);

    $items = collect(nyaaPreview()['items'])->keyBy('key');

    expect([$items['1']['state'], $items['2']['state'], $items['2']['selected']])->toBe(['duplicate_episode', 'new', true]);
});

test('real title (Erai-raws, live nyaa.si 2026-11-08): "Season 2" in the name suggests the SubsPlease-style show, found by its exact name', function () {
    $show = metadataShow('Ao no Hako S2');
    heldRelease($show, '01');
    fakeNyaa([['title' => ERAI_AO_NO_HAKO_05_HEVC, 'id' => 1, 'hash' => hashFor('hako')]]);

    $series = nyaaPreview()['series'][0];

    expect($series)->toMatchArray(['key' => 'aonohako|s2', 'suggestedName' => 'Ao no Hako S2', 'season' => 2])
        ->and($series['target'])->toBe(['kind' => 'existing', 'showId' => $show->id, 'showName' => 'Ao no Hako S2', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null])
        ->and($series['candidates'][0]['why'])->toBe("Named exactly 'Ao no Hako S2'.");
});

test('real title (Erai-raws, live nyaa.si 2026-11-08): Hyouken II finds the SubsPlease "wo" show: the particle folds, the roman II is the season', function () {
    // As on the test stand: SubsPlease's show, linked to AniList's entry with "wo".
    $anime = metadataAnime(['title_romaji' => 'Hyouken no Majutsushi ga Sekai wo Suberu II']);
    $show = mcpLinkedShow($anime, 'Hyouken no Majutsushi ga Sekai wo Suberu S2');
    heldRelease($show, '04');
    fakeNyaa([
        ['title' => ERAI_HYOUKEN_05_HEVC, 'id' => 1, 'hash' => hashFor('hevc')],
        ['title' => ERAI_HYOUKEN_05_AVC, 'id' => 2, 'hash' => hashFor('avc')],
    ]);

    $series = nyaaPreview()['series'][0];

    // Before the fold this was a new show with the show as a similarity candidate (81).
    expect($series)->toMatchArray(['suggestedName' => 'Hyouken no Majutsushi ga Sekai o Suberu S2', 'season' => 2])
        ->and($series['target'])->toBe([
            'kind' => 'existing', 'showId' => $show->id, 'showName' => 'Hyouken no Majutsushi ga Sekai wo Suberu S2', 'trackable' => true, 'trackBlockedReason' => null, 'anime' => null,
        ])
        ->and($series['candidates'])->toBe([[
            'kind' => 'show', 'showId' => $show->id, 'showName' => 'Hyouken no Majutsushi ga Sekai wo Suberu S2',
            'why' => "Same name as 'Hyouken no Majutsushi ga Sekai o Suberu S2', ignoring case, punctuation and season wording.", 'score' => 95, 'trackable' => true,
        ]]);
});

// ── Target decision: a single candidate >= 90 with no runner-up within 10 ────

test('target: a linked anime\'s show and an unlinked anime scoring as high are a tie: a new show, both offered (show first)', function () {
    // Constructed: two anime both named "Sensha Isu", only one linked. The unlinked one is a candidate itself now,
    // so the two are runners-up to each other and nothing is picked.
    $linked = mcpLinkedShow(metadataAnime(['title_romaji' => 'Sensha Isu']), 'Tanku Cheaa');
    $unlinked = metadataAnime(['title_romaji' => 'Sensha Isu', 'title_english' => 'Tank Chair (unlinked)']);
    fakeNyaa([['title' => TOONSHUB_S01E06, 'id' => 7, 'hash' => hashFor('toons-06')]]);

    $series = nyaaPreview()['series'][0];

    expect($series['target'])->toMatchArray(['kind' => 'new', 'name' => 'TANK CHAIR', 'anime' => null])
        ->and(array_map(fn (array $c) => [$c['kind'], $c['showId'] ?? $c['anime']['id'], $c['score']], $series['candidates']))
        ->toBe([['show', $linked->id, 100], ['anime', $unlinked->id, 100]]);
});

test('target: two close candidates from any rule give a new show; an exact name still wins over a close one', function () {
    // Constructed show names that normalize like "Demons Crest" (rule 2, 95 each).
    $a = metadataShow("Demon's Crest!", ['slug' => 'demons-crest-a']);
    $b = metadataShow('DEMONS-CREST', ['slug' => 'demons-crest-b']);
    fakeNyaa([['title' => ERAI_01, 'id' => 1, 'hash' => hashFor('erai-01')]]);

    $series = nyaaPreview()['series'][0];

    expect($series['target'])->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest'])
        ->and(collect($series['candidates'])->pluck('showId')->sort()->values()->all())->toBe([$a->id, $b->id]);

    $exact = metadataShow('Demons Crest', ['slug' => 'demons-crest-exact']);
    $series = nyaaPreview()['series'][0];

    expect($series['target'])->toMatchArray(['kind' => 'existing', 'showId' => $exact->id])
        ->and($series['candidates'][0])->toMatchArray(['showId' => $exact->id, 'score' => 100]);
});
