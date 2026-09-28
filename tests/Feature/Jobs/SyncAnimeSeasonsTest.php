<?php

declare(strict_types=1);

use App\Contracts\MetadataProvider;
use App\Jobs\FetchAnimeCover;
use App\Jobs\MatchShowsToAnime;
use App\Jobs\SyncAnimeSeasons;
use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeExternalId;
use App\Models\AnimePayload;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\AniList\AniListException;
use App\Services\Metadata\AniList\AniListMediaParser;
use App\Services\Metadata\AnimeSyncer;
use App\Services\Metadata\ProviderAnime;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([FetchAnimeCover::class, MatchShowsToAnime::class]);
    Sleep::fake(syncWithCarbon: true);
    // Current season SUMMER 2026, next FALL 2026.
    Carbon::setTestNow('2026-09-28 12:00:00');
});

/**
 * The FALL 2026 fixture as a complete (single-page) season.
 *
 * @return array<string, mixed>
 */
function fallSeasonResponse(?callable $mutate = null): array
{
    $response = anilistFixture('season_fall_2026_page1');
    $response['data']['Page']['pageInfo']['hasNextPage'] = false;

    if ($mutate !== null) {
        $response = $mutate($response);
    }

    return $response;
}

function runSeasonSync(SyncAnimeSeasons $job): void
{
    app()->call([$job, 'handle']);
}

test('a season sync upserts anime, external ids (AniList and MAL) and full payloads', function () {
    Http::fake(['graphql.anilist.co' => Http::response(fallSeasonResponse())]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    $anime = Anime::whereHas('externalIds', fn ($q) => $q->where('provider', 'anilist')->where('external_id', '195516'))->firstOrFail();

    expect(Anime::count())->toBe(5)
        ->and($anime->title_english)->toBe('The Apothecary Diaries Season 3')
        ->and($anime->genres)->toBe(['Drama', 'Mystery'])
        ->and($anime->season)->toBe('FALL')
        ->and($anime->season_year)->toBe(2026)
        ->and($anime->start_date->toDateString())->toBe('2026-10-02')
        ->and($anime->end_date)->toBeNull()
        ->and($anime->primary_provider)->toBe('anilist')
        ->and($anime->externalId('mal'))->toBe('61987')
        ->and($anime->payloads()->sole()->payload)->toEqual(fallSeasonResponse()['data']['Page']['media'][0])
        ->and(AnimeExternalId::count())->toBe(10);

    // nextAiringEpisode becomes a stored airing; nothing has aired yet.
    $airing = AnimeAiring::where('anime_id', $anime->id)->sole();
    expect($airing->episode)->toBe(1)
        ->and($airing->airs_at->getTimestamp())->toBe(1790949600)
        ->and($airing->is_estimate)->toBeFalse()
        ->and($anime->episodes_aired)->toBe(0);
});

test('running the sync twice changes nothing: no duplicates, payloads replaced', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->push(fallSeasonResponse())
        ->push(fallSeasonResponse(function (array $response) {
            $response['data']['Page']['media'][0]['description'] = 'Updated description.';

            return $response;
        }))]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));
    $ids = Anime::orderBy('id')->pluck('id')->all();

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    expect(Anime::orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(Anime::count())->toBe(5)
        ->and(AnimeExternalId::count())->toBe(10)
        ->and(AnimePayload::count())->toBe(5)
        ->and(AnimeAiring::count())->toBe(5)
        ->and(Anime::where('title_romaji', 'Kusuriya no Hitorigoto 3rd Season')->value('description'))->toBe('Updated description.')
        ->and(AnimePayload::where('anime_id', $ids[0])->sole()->payload['description'])->toBe('Updated description.');
});

test('covers are queued only for new entries in the current or next season, and matching runs after', function () {
    Http::fake(['graphql.anilist.co' => Http::response(fallSeasonResponse())]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    Queue::assertPushed(FetchAnimeCover::class, 5);
    Queue::assertPushed(MatchShowsToAnime::class, fn (MatchShowsToAnime $job) => $job->showId === null);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    Queue::assertPushed(FetchAnimeCover::class, 5);
});

test('an existing entry whose cover URL changed gets its cover fetched again', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->push(fallSeasonResponse())
        ->push(fallSeasonResponse(function (array $response) {
            $response['data']['Page']['media'][1]['coverImage']['large'] = 'https://s4.anilist.co/file/anilistcdn/media/anime/cover/medium/new.jpg';

            return $response;
        }))]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    // As if the first batch of cover jobs had run: their unique locks are released.
    Queue::pushed(FetchAnimeCover::class)->each(fn (FetchAnimeCover $job) => (new UniqueLock(Cache::store()))->release($job));

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    $changed = Anime::where('cover_url', 'like', '%/new.jpg')->sole();

    Queue::assertPushed(FetchAnimeCover::class, 6);
    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $changed->id);
});

test('an old season sync stores entries but fetches no covers for them', function () {
    Http::fake(['graphql.anilist.co' => Http::response(fallSeasonResponse(function (array $response) {
        foreach ($response['data']['Page']['media'] as &$media) {
            $media['seasonYear'] = 2019;
        }

        return $response;
    }))]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2019]]));

    expect(Anime::count())->toBe(5);
    Queue::assertNotPushed(FetchAnimeCover::class);
});

test('--all-linked refreshes linked anime outside the synced seasons in one batched request', function () {
    $media = anilistFixture('season_summer_2026_finished')['data']['Page']['media'][0];
    $linked = metadataAnime(['title_romaji' => 'Old title', 'status' => 'RELEASING'], anilistId: (string) $media['id']);
    ShowAnimeLink::create(['show_id' => metadataShow('Mushoku Tensei S3')->id, 'anime_id' => $linked->id, 'confidence' => 100, 'source' => 'manual', 'linked_at' => now()]);
    metadataAnime(['title_romaji' => 'Unlinked, not refreshed'], anilistId: '1');

    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->push(fallSeasonResponse())
        ->push(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => [$media]]]])]);

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]], allLinked: true));

    expect($linked->fresh()->title_romaji)->toBe('Mushoku Tensei III: Isekai Ittara Honki Dasu')
        ->and($linked->fresh()->status)->toBe('FINISHED');

    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => (json_decode($request->body(), true)['variables']['ids'] ?? null) === [$media['id']]);
});

test('a rate limit mid-sync releases the job for the advertised wait instead of failing it', function () {
    Http::fake(['graphql.anilist.co' => Http::response('<html>429</html>', 429, ['Retry-After' => '55'])]);

    $job = (new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]))->withFakeQueueInteractions();

    runSeasonSync($job);

    $job->assertReleased(55);
    Queue::assertNotPushed(MatchShowsToAnime::class);
});

test('a real provider error fails the job (and so counts toward maxExceptions)', function () {
    Http::fake(['graphql.anilist.co' => Http::response(['errors' => [['message' => 'Internal Server Error', 'status' => 500]]], 500)]);

    $job = (new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]))->withFakeQueueInteractions();

    expect(fn () => runSeasonSync($job))->toThrow(AniListException::class);
});

test('a MAL id already claimed by another anime is skipped, not fatal', function () {
    $other = metadataAnime(['title_romaji' => 'Squatter'], anilistId: '1');
    $other->externalIds()->create(['provider' => 'mal', 'external_id' => '61987']);

    $entry = app(AniListMediaParser::class)->parse(anilistFixture('season_fall_2026_page1')['data']['Page']['media'][0]);
    $anime = app(AnimeSyncer::class)->upsert($entry)->anime;

    expect($anime->externalId('anilist'))->toBe('195516')
        ->and($anime->fresh()->externalIds->pluck('provider')->all())->toBe(['anilist'])
        ->and($other->fresh()->externalId('mal'))->toBe('61987');
});

test('the job is unique per season set', function () {
    $job = SyncAnimeSeasons::weekly();

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('SPRING-2026,SUMMER-2026,FALL-2026+linked')
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

test('the provider binding is used, so a stub provider needs no HTTP at all', function () {
    app()->instance(MetadataProvider::class, new class implements MetadataProvider
    {
        public function key(): string
        {
            return 'stub';
        }

        public function capabilities(): array
        {
            return [];
        }

        public function season(string $season, int $year): iterable
        {
            yield new ProviderAnime(
                provider: 'stub', externalId: 'x1', otherExternalIds: [], titleRomaji: 'Stubbed', titleEnglish: null,
                titleNative: null, synonyms: [], description: null, genres: [], format: null, status: null,
                episodesTotal: null, season: $season, seasonYear: $year, startDate: null, endDate: null,
                durationMinutes: null, coverUrl: null, bannerUrl: null, siteUrl: null, isAdult: false,
                nextAiring: null, raw: ['id' => 'x1'],
            );
        }

        public function byExternalId(string $id): ?ProviderAnime
        {
            return null;
        }

        public function byExternalIds(array $ids): array
        {
            return [];
        }

        public function search(string $query, int $limit = 10): array
        {
            return [];
        }

        public function schedule(string $externalId): array
        {
            return [];
        }

        public function schedules(array $externalIds): array
        {
            return [];
        }
    });

    runSeasonSync(new SyncAnimeSeasons([['season' => 'FALL', 'year' => 2026]]));

    expect(Anime::sole()->primary_provider)->toBe('stub')
        ->and(Anime::sole()->externalId('stub'))->toBe('x1');
});
