<?php

declare(strict_types=1);

use App\Jobs\SyncAnimeAirings;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\AnimeAiringWriter;
use App\Services\Metadata\AnimeSeasons;
use App\Services\Metadata\ProviderAiring;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow('2026-09-28 12:00:00');
});

function airing(int $episode, string $airsAt): ProviderAiring
{
    return new ProviderAiring($episode, CarbonImmutable::parse($airsAt, 'UTC'));
}

function linkAnime(Anime $anime, string $showName): void
{
    ShowAnimeLink::create([
        'show_id' => metadataShow($showName)->id,
        'anime_id' => $anime->id,
        'confidence' => 90,
        'source' => 'auto',
        'linked_at' => now(),
    ]);
}

test('episodes_aired is the highest episode that has aired, from the full schedule', function () {
    $anime = metadataAnime(['status' => 'RELEASING']);

    app(AnimeAiringWriter::class)->replace($anime, 'anilist', [
        airing(1, '2026-09-07 15:00'),
        airing(2, '2026-09-14 15:00'),
        airing(3, '2026-09-21 15:00'),
        airing(4, '2026-10-05 15:00'),
    ]);

    expect($anime->fresh()->episodes_aired)->toBe(3);
});

test('episodes_aired is nextAiringEpisode minus one when only the next airing is known', function () {
    $anime = metadataAnime(['status' => 'RELEASING']);

    app(AnimeAiringWriter::class)->merge($anime, 'anilist', [airing(9, '2026-10-02 15:00')]);

    expect($anime->fresh()->episodes_aired)->toBe(8);
});

test('when the stored schedule has a gap, the next airing still wins', function () {
    $anime = metadataAnime(['status' => 'RELEASING']);
    $writer = app(AnimeAiringWriter::class);

    // An old sync saw up to episode 3; the season sync now reports episode 8 as next.
    $writer->merge($anime, 'anilist', [airing(3, '2026-08-01 15:00')]);
    $writer->merge($anime, 'anilist', [airing(8, '2026-10-01 15:00')]);

    expect($anime->fresh()->episodes_aired)->toBe(7);
});

test('a finished anime with no schedule falls back to its total, anything else to null', function () {
    $finished = metadataAnime(['status' => 'FINISHED', 'episodes_total' => 12]);
    $unknown = metadataAnime(['status' => 'NOT_YET_RELEASED', 'episodes_total' => 12]);
    $writer = app(AnimeAiringWriter::class);

    $writer->refreshEpisodesAired($finished);
    $writer->refreshEpisodesAired($unknown);

    expect($finished->fresh()->episodes_aired)->toBe(12)
        ->and($unknown->fresh()->episodes_aired)->toBeNull();
});

test('a full schedule replaces upcoming airings the provider dropped, but keeps history', function () {
    $anime = metadataAnime(['status' => 'RELEASING']);
    $writer = app(AnimeAiringWriter::class);

    $writer->replace($anime, 'anilist', [
        airing(1, '2026-09-14 15:00'),
        airing(2, '2026-09-21 15:00'),
        airing(3, '2026-10-05 15:00'),
        airing(4, '2026-10-12 15:00'),
    ]);

    // Episode 3 was delayed a week and episode 4 dropped from the list; the past ones aren't listed any more.
    $writer->replace($anime, 'anilist', [airing(3, '2026-10-12 15:00')]);

    expect(AnimeAiring::where('anime_id', $anime->id)->orderBy('episode')->get()
        ->map(fn (AnimeAiring $a) => [$a->episode, $a->airs_at->toDateString()])->all())
        ->toBe([[1, '2026-09-14'], [2, '2026-09-21'], [3, '2026-10-12']]);
});

test('an empty schedule removes nothing', function () {
    $anime = metadataAnime(['status' => 'RELEASING']);
    $writer = app(AnimeAiringWriter::class);

    $writer->replace($anime, 'anilist', [airing(5, '2026-10-05 15:00')]);
    $writer->replace($anime, 'anilist', []);

    expect(AnimeAiring::where('anime_id', $anime->id)->count())->toBe(1);
});

test('the airing sync targets linked airing anime and the unfinished current season', function () {
    $linkedAiring = metadataAnime(['status' => 'RELEASING', 'season' => 'SPRING', 'season_year' => 2025], '11');
    linkAnime($linkedAiring, 'One Piece');
    $linkedUpcoming = metadataAnime(['status' => 'NOT_YET_RELEASED', 'season' => 'FALL', 'season_year' => 2026], '12');
    linkAnime($linkedUpcoming, 'Black Clover S2');
    $linkedFinished = metadataAnime(['status' => 'FINISHED', 'season' => 'SPRING', 'season_year' => 2025], '13');
    linkAnime($linkedFinished, 'Done Show');
    metadataAnime(['status' => 'RELEASING', 'season' => 'SUMMER', 'season_year' => 2026], '14');
    metadataAnime(['status' => 'FINISHED', 'season' => 'SUMMER', 'season_year' => 2026], '15');
    metadataAnime(['status' => 'RELEASING', 'season' => 'FALL', 'season_year' => 2026], '16');

    $ids = fn (bool $linkedOnly) => (new SyncAnimeAirings($linkedOnly))
        ->targets(app(AnimeSeasons::class))
        ->get()
        ->map(fn (Anime $anime) => $anime->externalIds()->value('external_id'))
        ->sort()->values()->all();

    expect($ids(false))->toBe(['11', '12', '14'])
        ->and($ids(true))->toBe(['11', '12']);
});

test('the airing sync stores each real schedule and derives episodes_aired, in one request per 50 anime', function () {
    $bleach = metadataAnime(['status' => 'RELEASING', 'season' => 'SUMMER', 'season_year' => 2026], '185874');
    $seihantai = metadataAnime(['status' => 'RELEASING', 'season' => 'SUMMER', 'season_year' => 2026], '210031');

    // The real batch response, minus the two anime this test doesn't store.
    $response = anilistFixture('schedules_batch');
    $response['data']['Page']['media'] = array_values(array_filter(
        $response['data']['Page']['media'],
        fn (array $media) => in_array($media['id'], [185874, 210031], true),
    ));
    Http::fake(['graphql.anilist.co' => Http::response($response)]);

    app()->call([new SyncAnimeAirings, 'handle']);

    expect(AnimeAiring::where('anime_id', $bleach->id)->count())->toBe(10)
        ->and(AnimeAiring::where('anime_id', $seihantai->id)->count())->toBe(13)
        // Aired up to 8 and 12 as of the test clock; next airing 9 and 13.
        ->and($bleach->fresh()->episodes_aired)->toBe(8)
        ->and($seihantai->fresh()->episodes_aired)->toBe(12);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => collect(json_decode($request->body(), true)['variables']['ids'])->sort()->values()->all() === [185874, 210031]);
});

test('with nothing to refresh, the airing sync makes no request', function () {
    app()->call([new SyncAnimeAirings, 'handle']);

    Http::assertNothingSent();
});

test('anime:sync-airings queues the job', function () {
    Queue::fake();

    $this->artisan('anime:sync-airings --linked')->assertSuccessful();

    Queue::assertPushed(SyncAnimeAirings::class, fn (SyncAnimeAirings $job) => $job->linkedOnly);
});
