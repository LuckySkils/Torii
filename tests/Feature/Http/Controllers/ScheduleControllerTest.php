<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeImage;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00:00');
    $this->withoutVite();
});

function airAt(Anime $anime, int $episode, string $airsAt, bool $estimate = false): AnimeAiring
{
    return AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => $episode, 'airs_at' => $airsAt, 'is_estimate' => $estimate]);
}

function linkTo(Anime $anime, string $showName, bool $tracked = false): Show
{
    $show = metadataShow($showName, ['is_tracked' => $tracked]);
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()]);

    return $show;
}

/**
 * @return array<int, array<string, mixed>>
 */
function schedule(string $query = ''): array
{
    return test()->get('/schedule'.($query === '' ? '' : '?'.$query))->assertOk()->viewData('page')['props']['airings'];
}

function weekRange(): string
{
    return 'from='.urlencode('2026-10-05T00:00:00Z').'&to='.urlencode('2026-10-12T00:00:00Z');
}

// ── Range ────────────────────────────────────────────────────────────────────

test('the range is [from, to): an airing at from is in, one at to is out', function () {
    $anime = metadataAnime(['title_romaji' => 'Boundary']);
    airAt($anime, 1, '2026-10-04 23:59:59');
    airAt($anime, 2, '2026-10-05 00:00:00');
    airAt($anime, 3, '2026-10-11 23:59:59');
    airAt($anime, 4, '2026-10-12 00:00:00');

    expect(array_column(schedule(weekRange()), 'episode'))->toBe([2, 3]);
});

test('a range in another offset is read as the instant it names', function () {
    $anime = metadataAnime();
    airAt($anime, 1, '2026-10-04 15:00:00'); // midnight 10-05 in Tokyo
    airAt($anime, 2, '2026-10-05 14:59:59');
    airAt($anime, 3, '2026-10-05 15:00:00');

    // One Tokyo calendar day, as a frontend in Japan would send it.
    $day = 'from='.urlencode('2026-10-05T00:00:00+09:00').'&to='.urlencode('2026-10-06T00:00:00+09:00');

    expect(array_column(schedule($day), 'episode'))->toBe([1, 2]);
    $this->get('/schedule?'.$day)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters.from', '2026-10-04T15:00:00+00:00')
        ->where('filters.to', '2026-10-05T15:00:00+00:00'));
});

test('without a range it is now to now + 7 days, past airings of the range included', function () {
    $anime = metadataAnime();
    airAt($anime, 1, '2026-10-05 11:00:00'); // an hour ago: before `from`
    airAt($anime, 2, '2026-10-05 12:00:00');
    airAt($anime, 3, '2026-10-12 11:59:59');
    airAt($anime, 4, '2026-10-12 12:00:00');

    expect(array_column(schedule(), 'episode'))->toBe([2, 3]);

    // An explicit range reaching into the past includes what already aired (10-05 00:00 to 10-12 00:00).
    expect(array_column(schedule(weekRange()), 'episode'))->toBe([1, 2]);
});

test('rows are one per airing, sorted by time across anime', function () {
    $a = metadataAnime(['title_romaji' => 'A']);
    $b = metadataAnime(['title_romaji' => 'B']);
    airAt($a, 1, '2026-10-06 15:00:00');
    airAt($b, 7, '2026-10-06 14:00:00');
    airAt($a, 2, '2026-10-08 15:00:00');

    $rows = schedule(weekRange());

    expect(array_map(fn (array $row) => [$row['anime']['titleRomaji'], $row['episode']], $rows))->toBe([['B', 7], ['A', 1], ['A', 2]]);
});

test('ranges over 45 days, reversed or unparseable are rejected', function (string $query, string $field) {
    $this->get('/schedule?'.$query)->assertSessionHasErrors($field);
})->with([
    '46 days' => ['from=2026-10-01T00:00:00Z&to=2026-11-16T00:00:00Z', 'to'],
    'reversed' => ['from=2026-10-10T00:00:00Z&to=2026-10-01T00:00:00Z', 'to'],
    'empty' => ['from=2026-10-10T00:00:00Z&to=2026-10-10T00:00:00Z', 'to'],
    'garbage' => ['from=next-tuesday-ish&to=2026-10-10T00:00:00Z', 'from'],
]);

test('exactly 45 days is allowed', function () {
    $this->get('/schedule?from=2026-10-01T00:00:00Z&to=2026-11-15T00:00:00Z')->assertOk()->assertSessionHasNoErrors();
});

// ── Filters ──────────────────────────────────────────────────────────────────

test('every anime with airings appears, linked or not, and linked/tracked filter through the link', function () {
    $unlinked = metadataAnime(['title_romaji' => 'Unlinked']);
    $linked = metadataAnime(['title_romaji' => 'Linked']);
    $tracked = metadataAnime(['title_romaji' => 'Tracked']);
    linkTo($linked, 'Linked Show');
    linkTo($tracked, 'Tracked Show', tracked: true);
    foreach ([$unlinked, $linked, $tracked] as $i => $anime) {
        airAt($anime, 1, '2026-10-06 1'.$i.':00:00');
    }

    $titles = fn (string $query) => array_map(fn (array $row) => $row['anime']['titleRomaji'], schedule(weekRange().$query));

    expect($titles(''))->toBe(['Unlinked', 'Linked', 'Tracked'])
        ->and($titles('&linked=linked'))->toBe(['Linked', 'Tracked'])
        ->and($titles('&linked=unlinked'))->toBe(['Unlinked'])
        ->and($titles('&tracked=tracked'))->toBe(['Tracked'])
        ->and($titles('&linked=unlinked&tracked=tracked'))->toBe([]);
});

test('season, year and format filter; absent season and year mean any', function () {
    $fallTv = metadataAnime(['title_romaji' => 'Fall TV', 'season' => 'FALL', 'season_year' => 2026, 'format' => 'TV']);
    $fallOna = metadataAnime(['title_romaji' => 'Fall ONA', 'season' => 'FALL', 'season_year' => 2026, 'format' => 'ONA']);
    $oldTv = metadataAnime(['title_romaji' => 'Old TV', 'season' => 'FALL', 'season_year' => 1999, 'format' => 'TV']);
    foreach ([$fallTv, $fallOna, $oldTv] as $i => $anime) {
        airAt($anime, 100, '2026-10-06 1'.$i.':00:00');
    }

    $titles = fn (string $query) => array_map(fn (array $row) => $row['anime']['titleRomaji'], schedule(weekRange().$query));

    expect($titles(''))->toBe(['Fall TV', 'Fall ONA', 'Old TV'])
        ->and($titles('&season=fall&year=2026'))->toBe(['Fall TV', 'Fall ONA'])
        ->and($titles('&year=1999'))->toBe(['Old TV'])
        ->and($titles('&format[]=TV'))->toBe(['Fall TV', 'Old TV'])
        ->and($titles('&format=ona,movie&season=FALL'))->toBe(['Fall ONA']);
});

test('adult entries are hidden by default, and can be included or isolated', function () {
    $general = metadataAnime(['title_romaji' => 'General']);
    $adult = metadataAnime(['title_romaji' => 'Adult', 'is_adult' => true]);
    airAt($general, 1, '2026-10-06 10:00:00');
    airAt($adult, 1, '2026-10-06 11:00:00');

    $titles = fn (string $query) => array_map(fn (array $row) => $row['anime']['titleRomaji'], schedule(weekRange().$query));

    expect($titles(''))->toBe(['General'])
        ->and($titles('&adult=include'))->toBe(['General', 'Adult'])
        ->and($titles('&adult=only'))->toBe(['Adult']);
});

test('filters are reflected back, with filter options shaped as on /anime', function () {
    metadataAnime(['format' => 'TV', 'season_year' => 2026]);

    $this->get('/schedule?'.weekRange().'&season=fall&year=2026&format[]=tv&linked=linked&tracked=tracked&adult=include')
        ->assertInertia(fn (AssertableInertia $page) => $page
            // The page itself comes with the frontend session.
            ->component('Schedule/Index', false)
            ->where('filters', [
                'from' => '2026-10-05T00:00:00+00:00',
                'to' => '2026-10-12T00:00:00+00:00',
                'season' => 'FALL',
                'year' => 2026,
                'format' => ['TV'],
                'linked' => 'linked',
                'tracked' => 'tracked',
                'adult' => 'include',
            ])
            ->where('filterOptions.seasons', ['WINTER', 'SPRING', 'SUMMER', 'FALL'])
            ->where('filterOptions.years', [2026])
            ->where('filterOptions.formats', [['value' => 'TV', 'count' => 1]]));

    $this->get('/schedule')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters.season', null)
        ->where('filters.year', null)
        ->where('filters.linked', 'all')
        ->where('filters.tracked', 'all')
        ->where('filters.adult', 'hide'));
});

// ── Row contents ─────────────────────────────────────────────────────────────

test('a row carries the airing, the anime with its cover metadata, and the linked show', function () {
    $anime = metadataAnime([
        'title_romaji' => 'Romaji', 'title_english' => 'English', 'format' => 'TV', 'status' => 'RELEASING',
        'episodes_total' => 12, 'season' => 'FALL', 'season_year' => 2026,
    ]);
    $cover = AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => 'irrelevant', 'size' => 1, 'width' => 460, 'height' => 650, 'sha256' => str_repeat('b', 64), 'fetched_at' => now()]);
    $show = linkTo($anime, 'The Show', tracked: true);
    airAt($anime, 5, '2026-10-07 15:30:00', estimate: true);

    expect(schedule(weekRange())[0])->toBe([
        'episode' => 5,
        'airsAt' => '2026-10-07T15:30:00+00:00',
        'isEstimate' => true,
        'anime' => [
            'id' => $anime->id,
            'titleRomaji' => 'Romaji',
            'titleEnglish' => 'English',
            'coverUrl' => "/anime/{$anime->id}/cover?v=".substr($cover->sha256, 0, 8),
            'coverWidth' => 460,
            'coverHeight' => 650,
            'format' => 'TV',
            'status' => 'RELEASING',
            'episodesTotal' => 12,
            'season' => 'FALL',
            'seasonYear' => 2026,
            'isAdult' => false,
        ],
        'show' => ['id' => $show->id, 'name' => 'The Show', 'isTracked' => true],
        'releaseState' => null, // future
        'isNewSeries' => false,
    ]);
});

test('with several linked shows, the tracked one is the row\'s show', function () {
    $anime = metadataAnime();
    linkTo($anime, 'A Untracked');
    $tracked = linkTo($anime, 'B Tracked', tracked: true);
    airAt($anime, 3, '2026-10-06 10:00:00');

    expect(schedule(weekRange())[0]['show']['id'])->toBe($tracked->id);
});

function releaseFor(Show $show, ?string $episode, ?string $downloadedAt = null, bool $batch = false, ?int $from = null, ?int $to = null): void
{
    static $n = 0;
    $n++;

    Release::create([
        'show_id' => $show->id, 'guid' => "SCHED-{$n}", 'title' => "[SubsPlease] {$show->name} - {$episode} (1080p) [{$n}].mkv",
        'episode' => $episode, 'is_batch' => $batch, 'batch_from' => $from, 'batch_to' => $to, 'resolution' => '1080p',
        'link' => "magnet:?xt=urn:btih:SCHED{$n}", 'published_at' => now()->subDay(), 'first_seen_at' => now()->subDay(),
        'downloaded_at' => $downloadedAt,
    ]);
}

test('releaseState: downloaded, released, waiting for past airings; null in the future or unlinked', function () {
    $anime = metadataAnime(['title_romaji' => 'Linked']);
    $show = linkTo($anime, 'Linked Show', tracked: true);
    releaseFor($show, '01', downloadedAt: '2026-10-05 01:00:00');
    releaseFor($show, '02');
    airAt($anime, 1, '2026-10-05 00:30:00');
    airAt($anime, 2, '2026-10-05 01:30:00');
    airAt($anime, 3, '2026-10-05 02:30:00');
    airAt($anime, 4, '2026-10-06 02:30:00'); // future

    $unlinked = metadataAnime(['title_romaji' => 'Unlinked']);
    airAt($unlinked, 1, '2026-10-05 03:00:00');

    $states = array_map(fn (array $row) => [$row['anime']['titleRomaji'], $row['episode'], $row['releaseState']], schedule(weekRange()));

    expect($states)->toBe([
        ['Linked', 1, 'downloaded'],
        ['Linked', 2, 'released'],
        ['Linked', 3, 'waiting'],
        ['Unlinked', 1, null],
        ['Linked', 4, null],
    ]);
});

test('releaseState matches episode numbers as numbers, and counts a batch covering the episode', function () {
    $anime = metadataAnime();
    $show = linkTo($anime, 'Batched', tracked: true);
    releaseFor($show, '07'); // "07" is episode 7
    releaseFor($show, null, downloadedAt: '2026-10-05 01:00:00', batch: true, from: 1, to: 5);
    airAt($anime, 3, '2026-10-05 01:00:00');
    airAt($anime, 7, '2026-10-05 02:00:00');
    airAt($anime, 8, '2026-10-05 03:00:00');

    expect(array_column(schedule(weekRange()), 'releaseState'))->toBe(['downloaded', 'released', 'waiting']);
});

test('absolute numbering (Hyakkano at 36 against 12) makes every state of that anime unknown, not waiting', function () {
    $s3 = metadataAnime(['title_romaji' => 'Hyakkano 3', 'episodes_total' => 12]);
    $show = linkTo($s3, 'Hyakkano', tracked: true);
    releaseFor($show, '35', downloadedAt: '2026-10-04 01:00:00');
    releaseFor($show, '36');
    airAt($s3, 11, '2026-10-05 01:00:00'); // aired, but AniList's episode 11 isn't the show's 11
    airAt($s3, 12, '2026-10-05 02:00:00');

    expect(array_column(schedule(weekRange()), 'releaseState'))->toBe([null, null]);
});

test('up to 2 episodes past the total (specials, .5s) still resolves states', function (string $highest, ?string $state) {
    $anime = metadataAnime(['episodes_total' => 12]);
    $show = linkTo($anime, 'Nearly', tracked: true);
    releaseFor($show, $highest);
    airAt($anime, 12, '2026-10-05 01:00:00');

    expect(schedule(weekRange())[0]['releaseState'])->toBe($state);
})->with([
    'two past' => ['14', 'waiting'],
    'three past' => ['15', null],
]);

test('with no known total, states resolve as usual', function () {
    $anime = metadataAnime(['episodes_total' => null]);
    $show = linkTo($anime, 'Open-ended', tracked: true);
    releaseFor($show, '1100');
    airAt($anime, 1101, '2026-10-05 01:00:00');

    expect(schedule(weekRange())[0]['releaseState'])->toBe('waiting');
});

test('isNewSeries: episode 1, or an anime that started within the last 14 days', function () {
    $fresh = metadataAnime(['title_romaji' => 'Fresh', 'start_date' => '2026-09-25']);
    $old = metadataAnime(['title_romaji' => 'Old', 'start_date' => '2026-07-01']);
    $premiere = metadataAnime(['title_romaji' => 'Premiere', 'start_date' => null]);
    airAt($fresh, 3, '2026-10-06 10:00:00');
    airAt($old, 14, '2026-10-06 11:00:00');
    airAt($premiere, 1, '2026-10-06 12:00:00');

    expect(array_map(fn (array $row) => [$row['anime']['titleRomaji'], $row['isNewSeries']], schedule(weekRange())))->toBe([
        ['Fresh', true],
        ['Old', false],
        ['Premiere', true],
    ]);
});

// ── Queries ──────────────────────────────────────────────────────────────────

/**
 * @return array<int, string>
 */
function scheduleQueries(): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    schedule(weekRange());
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

function seedScheduledAnime(int $count, string $batch): void
{
    for ($i = 0; $i < $count; $i++) {
        $anime = metadataAnime(['title_romaji' => "Anime {$batch}{$i}"]);
        AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => base64_encode(str_repeat('x', 1000)), 'size' => 1000, 'width' => 460, 'height' => 650, 'sha256' => str_pad($batch.$i, 64, 'a'), 'fetched_at' => now()]);
        $show = linkTo($anime, "Show {$batch}{$i}", tracked: $i % 2 === 0);
        releaseFor($show, '01');
        airAt($anime, 1, '2026-10-05 0'.($i % 10).':00:00');
        airAt($anime, 2, '2026-10-06 0'.($i % 10).':00:00');
    }
}

test('a week of rows is a fixed number of queries, not one per row', function () {
    seedScheduledAnime(2, 'a');
    $few = count(scheduleQueries());

    seedScheduledAnime(12, 'b');
    $many = count(scheduleQueries());

    expect($many)->toBe($few);
});

test('the cover image data is never selected', function () {
    seedScheduledAnime(2, 'a');

    $imageQueries = array_values(array_filter(scheduleQueries(), fn (string $sql) => str_contains($sql, '"anime_images"')));

    expect($imageQueries)->not->toBeEmpty();

    foreach ($imageQueries as $sql) {
        expect($sql)->not->toContain('"data"')->and($sql)->not->toContain('*');
    }
});
