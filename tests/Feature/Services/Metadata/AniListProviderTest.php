<?php

declare(strict_types=1);

use App\Contracts\MetadataProvider;
use App\Services\Metadata\AniList\AniListException;
use App\Services\Metadata\AniList\AniListProvider;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-28 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

/**
 * @return array<string, mixed>
 */
function anilistRequestBody(Request $request): array
{
    return json_decode($request->body(), true);
}

test('the active provider is AniList, bound by its key', function () {
    $provider = app(MetadataProvider::class);

    expect($provider)->toBeInstanceOf(AniListProvider::class)
        ->and($provider->key())->toBe('anilist')
        ->and($provider->capabilities())->toBe(['seasons', 'schedule', 'images', 'descriptions']);
});

test('a season pages on hasNextPage only, ignoring the fake lastPage, 50 per page', function () {
    $lastPage = anilistFixture('season_summer_2026_finished');
    $lastPage['data']['Page']['pageInfo']['hasNextPage'] = false;

    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->push(anilistFixture('season_fall_2026_page1'))
        ->push($lastPage)]);

    $entries = iterator_to_array(app(MetadataProvider::class)->season('fall', 2026), false);

    expect($entries)->toHaveCount(8)
        ->and($entries[0]->externalId)->toBe('195516')
        ->and($entries[7]->externalId)->toBe('135865');

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === [
        'season' => 'FALL', 'seasonYear' => 2026, 'page' => 1, 'perPage' => 50,
    ]);
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables']['page'] === 2
        && str_contains(anilistRequestBody($request)['query'], 'hasNextPage')
        && str_contains(anilistRequestBody($request)['query'], 'type: ANIME'));
});

test('entry queries ask for tags, studios and external links, and the payload keeps them', function () {
    $response = anilistFixture('season_fall_2026_extra_fields');
    $response['data']['Page']['pageInfo']['hasNextPage'] = false;
    Http::fake(['graphql.anilist.co' => Http::response($response)]);

    $entries = iterator_to_array(app(MetadataProvider::class)->season('FALL', 2026), false);

    expect($entries)->toHaveCount(3)
        ->and($entries[0]->raw['studios']['nodes'][0])->toBe(['id' => 43, 'name' => 'ufotable'])
        ->and($entries[0]->raw['tags'][0])->toHaveKeys(['name', 'rank', 'isMediaSpoiler'])
        ->and($entries[0]->raw['externalLinks'][0])->toHaveKeys(['site', 'url', 'type', 'language']);

    Http::assertSent(fn (Request $request) => str_contains(anilistRequestBody($request)['query'], 'studios(isMain: true) { nodes { id name } }')
        && str_contains(anilistRequestBody($request)['query'], 'tags { name rank isMediaSpoiler }')
        && str_contains(anilistRequestBody($request)['query'], 'externalLinks { site url type language }'));
});

test('an empty season is one request and no entries', function () {
    Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['pageInfo' => ['hasNextPage' => false], 'media' => []]]])]);

    expect(iterator_to_array(app(MetadataProvider::class)->season('WINTER', 2030)))->toBe([]);
    Http::assertSentCount(1);
});

test('byExternalId returns the parsed entry (real response)', function () {
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('media_by_id'))]);

    $anime = app(MetadataProvider::class)->byExternalId('21');

    expect($anime?->externalId)->toBe('21')
        ->and($anime->titleRomaji)->toBe('ONE PIECE')
        ->and($anime->startDate)->toBe('1999-10-20')
        ->and($anime->endDate)->toBeNull()
        ->and($anime->status)->toBe('RELEASING')
        ->and($anime->nextAiring?->episode)->toBe(1181);

    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === ['id' => 21]);
});

test('byExternalId returns null for AniList\'s real 404 Not Found', function () {
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('media_not_found'), 404)]);

    expect(app(MetadataProvider::class)->byExternalId('999999999'))->toBeNull();
});

test('byExternalIds returns every known id from one request (real response)', function () {
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('media_by_ids'))]);

    $entries = app(MetadataProvider::class)->byExternalIds(['21', '235']);

    expect(array_map(fn ($entry) => $entry->externalId, $entries))->toBe(['21', '235']);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === ['ids' => [21, 235], 'page' => 1, 'perPage' => 50]);
});

test('byExternalIds asks for up to 50 ids per request', function () {
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('media_by_ids'))]);

    app(MetadataProvider::class)->byExternalIds(array_map('strval', range(1, 120)));

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => count(anilistRequestBody($request)['variables']['ids']) === 50
        && str_contains(anilistRequestBody($request)['query'], 'id_in: $ids'));
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables']['ids'] === range(101, 120));
});

test('search is fail-fast: one request, no retry, even when rate limited', function () {
    Http::fake(['graphql.anilist.co' => Http::response('<html>429</html>', 429, ['Retry-After' => '3'])]);

    expect(fn () => app(MetadataProvider::class)->search('frieren'))
        ->toThrow(fn (AniListException $e) => expect($e->rateLimited)->toBeTrue());

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('search sends the query text and limit and parses the real response', function () {
    Http::fake(['graphql.anilist.co' => Http::response(anilistFixture('search_one_piece'))]);

    $results = app(MetadataProvider::class)->search('One Piece', 5);

    expect(array_map(fn ($entry) => $entry->titleRomaji, $results))->toBe(['ONE PIECE', 'THE ONE PIECE', 'ONE PIECE (Movie)']);
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === ['search' => 'One Piece', 'perPage' => 5]
        && str_contains(anilistRequestBody($request)['query'], 'sort: SEARCH_MATCH'));
});

/**
 * Answers the batch request with the real schedules_batch fixture, and One Piece's
 * page 2 with the real schedule_page2 fixture. Two answers are made up because
 * no page after them was saved: One Piece's page 2 is marked last (really it has
 * a page 3), and Tsuihou's page 2 holds just its 26th episode.
 */
function fakeSchedules(): void
{
    $page2 = anilistFixture('schedule_page2');
    $page2['data']['Media']['airingSchedule']['pageInfo']['hasNextPage'] = false;

    Http::fake(function (Request $request) use ($page2) {
        $variables = anilistRequestBody($request)['variables'];

        return match (true) {
            isset($variables['ids']) => Http::response(anilistFixture('schedules_batch')),
            $variables['id'] === 21 => Http::response($page2),
            $variables['id'] === 180136 => Http::response(['data' => ['Media' => ['airingSchedule' => [
                'pageInfo' => ['hasNextPage' => false],
                'nodes' => [['episode' => 26, 'airingAt' => 1798125960]],
            ]]]]),
        };
    });
}

test('schedules batches anime, follows airingSchedule paging 25 at a time, and merges nextAiringEpisode', function () {
    fakeSchedules();

    $schedules = app(MetadataProvider::class)->schedules(['185874', '180136', '210031', '21']);
    $episodes = fn (string $id) => array_map(fn ($airing) => $airing->episode, $schedules[$id]);

    expect(array_keys($schedules))->toEqualCanonicalizing([21, 180136, 185874, 210031])
        // Short runs fit in the first page.
        ->and($episodes('185874'))->toBe(range(1, 10))
        ->and($episodes('210031'))->toBe(range(1, 13))
        // 26 episodes: 25 nested + the follow-up page.
        ->and($episodes('180136'))->toBe(range(1, 26))
        // One Piece: two pages of 25, plus nextAiringEpisode (1181) merged in.
        ->and($episodes('21'))->toBe([...range(1123, 1172), 1181])
        ->and($schedules['185874'][0]->airsAt->getTimestamp())->toBe(1784988000);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => str_contains(anilistRequestBody($request)['query'], 'airingSchedule(page: 1, perPage: 25)'));
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === ['id' => 21, 'page' => 2, 'perPage' => 25]);
    Http::assertSent(fn (Request $request) => anilistRequestBody($request)['variables'] === ['id' => 180136, 'page' => 2, 'perPage' => 25]);
});

test('schedule() for one anime uses the same batched query', function () {
    fakeSchedules();

    expect(array_map(fn ($airing) => $airing->episode, app(MetadataProvider::class)->schedule('210031')))->toBe(range(1, 13));
});
