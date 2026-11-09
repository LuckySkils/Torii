<?php

declare(strict_types=1);

use App\Enums\LinkSource;
use App\Events\ShowDiscovered;
use App\Jobs\FetchAnimeCover;
use App\Models\Anime;
use App\Models\AnimeImage;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeRejection;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/NyaaHelpers.php';

/*
 * A Nyaa series can target an anime of the catalog (§16): an anime without a show is a
 * candidate itself, picking it means a new show linked to it on confirm. The
 * Erai-raws title is the real production case, verbatim; everything else here
 * is constructed.
 */
const DEMONS_CREST_ERAI = '[Erai-raws] Demons Crest - 01 [1080p AMZN WEBRip HEVC EAC3][MultiSub][B2E1DE46]';
const DEMONS_CREST_ERAI_02 = '[Erai-raws] Demons Crest - 02 [1080p AMZN WEBRip HEVC EAC3][MultiSub][0A1B2C3D]'; // constructed
// Live nyaa.si on the test stand (2026-11-09), the same anime under its English title.
const DEVILS_CREST_TOONSHUB = "[ToonsHub] Devils Crest S01E01 1080p AMZN WEB-DL DUAL DDP2.0 H.264 (Demons' Crest, Dual-Audio, Multi-Subs)";

beforeEach(function () {
    Http::preventStrayRequests();
    Queue::fake([FetchAnimeCover::class]);
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow('2026-11-09 12:00:00');
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => '',
        'subtracker.qbittorrent.password' => '',
    ]);
});

/** Anime #277 as in production: romaji "Demons' Crest", English "Devils' Crest", Fall 2026. */
function demonsCrestAnime(): Anime
{
    return metadataAnime([
        'title_romaji' => "Demons' Crest",
        'title_english' => "Devils' Crest",
        'season' => 'FALL',
        'season_year' => 2026,
        'format' => 'TV',
    ]);
}

/**
 * @return array<string, mixed>
 */
function demonsCrestRef(Anime $anime, ?string $coverUrl = null): array
{
    return [
        'id' => $anime->id, 'title' => "Devils' Crest", 'titleRomaji' => "Demons' Crest", 'titleEnglish' => "Devils' Crest",
        'season' => 'FALL', 'seasonYear' => 2026, 'format' => 'TV', 'coverUrl' => $coverUrl,
    ];
}

function linkShow(Show $show, Anime $anime): void
{
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 95, 'source' => 'auto', 'linked_at' => now()]);
}

/**
 * @param  array<string, mixed>  $preview
 * @return array<string, array<string, mixed>> by suggested name
 */
function animeTargetSeries(array $preview): array
{
    return collect($preview['series'])->keyBy('suggestedName')->all();
}

function demonsCrestFeed(): void
{
    fakeNyaa([
        ['title' => DEMONS_CREST_ERAI, 'id' => 1, 'hash' => hashFor('dc-01')],
        ['title' => DEMONS_CREST_ERAI_02, 'id' => 2, 'hash' => hashFor('dc-02')],
    ]);
}

/**
 * @param  array<string, mixed>  $preview
 * @param  array<string, mixed>  $target
 */
function retargetSeries(array $preview, array $target, string $seriesKey = 'demonscrest|s1'): TestResponse
{
    return test()->postJson("/import/nyaa/preview/{$preview['previewId']}/target", ['seriesKey' => $seriesKey, 'target' => $target]);
}

// ── Candidates and the auto target ───────────────────────────────────────────

test('real title (Erai-raws, production): an unlinked "Demons\' Crest" is an anime candidate and the target is a new show linked to it', function () {
    $anime = demonsCrestAnime();
    AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => '', 'size' => 0, 'sha256' => 'abcdef0123456789'.str_repeat('0', 48), 'fetched_at' => now()]);
    demonsCrestFeed();

    $series = nyaaPreview()['series'][0];
    $ref = demonsCrestRef($anime, "/anime/{$anime->id}/cover?v=abcdef01");

    expect($series['suggestedName'])->toBe('Demons Crest')
        ->and($series['candidates'])->toBe([[
            'kind' => 'anime', 'anime' => $ref, 'why' => "Anime title 'Demons' Crest', no show yet.", 'score' => 100, 'trackable' => false,
        ]])
        ->and($series['target'])->toBe([
            'kind' => 'new', 'name' => 'Demons Crest', 'trackable' => false,
            'trackBlockedReason' => "No SubsPlease releases: Torii's download rule only follows SubsPlease's feed.", 'anime' => $ref,
        ]);
});

test('an anime with a linked show offers that show only, never itself as well', function () {
    $anime = demonsCrestAnime();
    $show = metadataShow('Akuma no Monshou');
    linkShow($show, $anime);
    demonsCrestFeed();

    $series = nyaaPreview()['series'][0];

    expect(array_column($series['candidates'], 'kind'))->toBe(['show'])
        ->and($series['candidates'][0])->toMatchArray(['showId' => $show->id, 'why' => "Anime title 'Demons' Crest' of the linked anime."])
        ->and($series['target'])->toMatchArray(['kind' => 'existing', 'showId' => $show->id, 'anime' => null]);
});

test('a rejected pair: the anime counts as unlinked (as matching sees it), so it is an anime candidate and the target', function () {
    $anime = demonsCrestAnime();
    $show = metadataShow('Akuma no Monshou');
    linkShow($show, $anime);
    ShowAnimeRejection::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'rejected_at' => now()]);
    demonsCrestFeed();

    $series = nyaaPreview()['series'][0];

    expect(array_column($series['candidates'], 'kind'))->toBe(['anime'])
        ->and($series['target'])->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => demonsCrestRef($anime)]);
});

test('ranking: one list, best first; ties put shows before anime, anime by id; at most 5 in total', function () {
    // Constructed: six anime named like the series, scoring 100; two of them linked.
    $linked = metadataAnime(['title_romaji' => 'Demons Crest']);
    $show = metadataShow('Akuma no Monshou');
    linkShow($show, $linked);
    $unlinked = collect(range(1, 5))->map(fn (int $i) => metadataAnime(['title_romaji' => 'Demons Crest', 'title_english' => "Demons Crest ({$i})"]));
    $weaker = metadataAnime(['title_romaji' => 'Demons Crests']); // similarity, below 100
    demonsCrestFeed();

    $series = nyaaPreview()['series'][0];

    expect(array_map(fn (array $c) => [$c['kind'], $c['showId'] ?? $c['anime']['id']], $series['candidates']))
        ->toBe([['show', $show->id], ...$unlinked->take(4)->map(fn (Anime $a) => ['anime', $a->id])->all()])
        ->and($series['target'])->toMatchArray(['kind' => 'new', 'anime' => null])
        ->and(collect($series['candidates'])->pluck('anime.id')->contains($weaker->id))->toBeFalse();
});

test('an exact-name show still wins over an anime candidate', function () {
    demonsCrestAnime();
    $show = metadataShow('Demons Crest');
    demonsCrestFeed();

    $series = nyaaPreview()['series'][0];

    expect($series['target'])->toMatchArray(['kind' => 'existing', 'showId' => $show->id, 'anime' => null])
        ->and(array_column($series['candidates'], 'kind'))->toBe(['show', 'anime']);
});

test('real titles (Erai-raws, production; ToonsHub, live nyaa.si 2026-11-09): two series of one anime go to one new show', function () {
    Queue::fake();
    $anime = demonsCrestAnime();
    fakeNyaa([
        ['title' => DEMONS_CREST_ERAI, 'id' => 1, 'hash' => hashFor('dc-01')],
        ['title' => DEVILS_CREST_TOONSHUB, 'id' => 2, 'hash' => hashFor('toons-01')],
    ]);

    $preview = nyaaPreview();
    $series = animeTargetSeries($preview);

    // "Demons Crest" sorts first, so it names the show for both.
    expect(array_keys($series))->toBe(['Demons Crest', 'Devils Crest'])
        ->and($series['Devils Crest']['candidates'][0])->toMatchArray(['kind' => 'anime', 'why' => "Anime title 'Devils' Crest', no show yet."])
        ->and($series['Demons Crest']['target'])->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => demonsCrestRef($anime)])
        ->and($series['Devils Crest']['target'])->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => demonsCrestRef($anime)]);

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '2']])->assertOk()->json();

    $show = Show::where('name', 'Demons Crest')->sole();

    expect($result['queued'])->toBe(2)
        ->and($result['warnings'])->toBe([])
        ->and($result['shows'])->toHaveCount(1)
        ->and(Show::count())->toBe(1)
        ->and(ShowAnimeLink::where('anime_id', $anime->id)->pluck('show_id')->all())->toBe([$show->id]);
});

// ── duplicate_episode across series of one show ─────────────────────────────

/** Live nyaa.si on the test stand (2026-11-09): the same episode 01 at 1080p from Erai-raws and ToonsHub. */
function crestPairFeed(): void
{
    fakeNyaa([
        ['title' => '[Erai-raws] Demons Crest - 01 [1080p AMZN WEB-DL AVC EAC3][MultiSub][AF4DCB26]', 'id' => 1, 'hash' => hashFor('erai-avc'), 'seeders' => 40],
        ['title' => DEVILS_CREST_TOONSHUB, 'id' => 2, 'hash' => hashFor('toons-264'), 'seeders' => 90],
        ['title' => '[Erai-raws] Demons Crest - 01 [540p AMZN WEB-DL AVC EAC3][MultiSub][97E6E5CC]', 'id' => 3, 'hash' => hashFor('erai-540')],
    ]);
}

/**
 * @param  array<string, mixed>  $preview
 * @return array<string, array{0: string, 1: bool, 2: string|null}>
 */
function crestStates(array $preview): array
{
    return collect($preview['items'])->mapWithKeys(fn (array $i) => [$i['key'] => [$i['state'], $i['selected'], $i['reason']]])->all();
}

test('real titles (live nyaa.si 2026-11-09): copies of an episode in two series going to one show leave one preselected; other resolutions are not copies', function () {
    demonsCrestAnime();
    crestPairFeed();

    $preview = nyaaPreview();

    // Both series go to the new "Demons Crest": ToonsHub's copy has more seeders, so it's kept.
    expect(crestStates($preview))->toEqual([
        '3' => ['new', true, null],
        '1' => ['duplicate_episode', false, 'Another copy of episode 01 is selected (ToonsHub).'],
        '2' => ['new', true, null],
    ]);
});

test('duplicate_episode follows retargets: apart, both are kept; together again (an existing show, or a new name in other case), one is', function () {
    demonsCrestAnime();
    crestPairFeed();
    $preview = nyaaPreview();

    $apart = retargetSeries($preview, ['newName' => 'Devils Crest'], 'devilscrest|s1')->assertOk()->json();

    expect([crestStates($apart)['1'][0], crestStates($apart)['2'][0]])->toBe(['new', 'new']);

    // A new name differing only in case and spaces is the same show.
    $together = retargetSeries($preview, ['newName' => '  demons crest '], 'devilscrest|s1')->assertOk()->json();

    expect(crestStates($together)['1'])->toBe(['duplicate_episode', false, 'Another copy of episode 01 is selected (ToonsHub).']);

    // Both series on one existing show (constructed name).
    $show = metadataShow('Akuma no Monshou');
    retargetSeries($preview, ['showId' => $show->id])->assertOk();
    $both = retargetSeries($preview, ['showId' => $show->id], 'devilscrest|s1')->assertOk()->json();

    expect([crestStates($both)['1'][0], crestStates($both)['2'][0]])->toBe(['duplicate_episode', 'new']);

    // Moving one away dissolves it again.
    $moved = retargetSeries($preview, ['newName' => 'Devils Crest'], 'devilscrest|s1')->assertOk()->json();

    expect([crestStates($moved)['1'][0], crestStates($moved)['2'][0]])->toBe(['new', 'new']);
});

test('confirm: new names differing only in case go to one show', function () {
    Queue::fake();
    crestPairFeed();
    $preview = nyaaPreview();
    retargetSeries($preview, ['newName' => 'DEMONS CREST'], 'devilscrest|s1')->assertOk();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '2']])->assertOk()->json();

    expect($result['queued'])->toBe(2)
        ->and(Show::count())->toBe(1)
        ->and(Release::whereIn('guid', ['https://nyaa.si/view/1', 'https://nyaa.si/view/2'])->distinct()->pluck('show_id')->all())->toBe([Show::sole()->id]);
});

// ── Retarget to an anime ─────────────────────────────────────────────────────

test('retarget { animeId }: no linked show gives a new show linked to it, named newName or the suggested name', function () {
    demonsCrestFeed();
    $preview = nyaaPreview();
    $anime = demonsCrestAnime();

    $target = retargetSeries($preview, ['animeId' => $anime->id])->assertOk()->json('series.0.target');

    expect($target)->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => demonsCrestRef($anime)]);

    $target = retargetSeries($preview, ['animeId' => $anime->id, 'newName' => "  Demons' Crest  "])->assertOk()->json('series.0.target');

    expect($target)->toMatchArray(['kind' => 'new', 'name' => "Demons' Crest", 'anime' => demonsCrestRef($anime)]);

    // A plain new name keeps today's behaviour: no anime.
    expect(retargetSeries($preview, ['newName' => 'Demons Crest'])->assertOk()->json('series.0.target'))
        ->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => null]);
});

test('retarget { animeId } without a name: an anime another series already goes to gets that series\' show name', function () {
    $anime = demonsCrestAnime();
    fakeNyaa([
        ['title' => DEMONS_CREST_ERAI, 'id' => 1, 'hash' => hashFor('dc-01')],
        ['title' => '[Judas] Akuma no Monshou - S01E01 [1080p]', 'id' => 3, 'hash' => hashFor('judas-01')], // constructed
    ]);
    $preview = nyaaPreview();

    $updated = retargetSeries($preview, ['animeId' => $anime->id], 'akumanomonshou|s1')->assertOk()->json();

    expect(animeTargetSeries($updated)['Akuma no Monshou']['target'])->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest', 'anime' => demonsCrestRef($anime)]);

    // A name given explicitly is kept.
    $updated = retargetSeries($preview, ['animeId' => $anime->id, 'newName' => 'Akuma no Monshou'], 'akumanomonshou|s1')->assertOk()->json();

    expect(animeTargetSeries($updated)['Akuma no Monshou']['target'])->toMatchArray(['kind' => 'new', 'name' => 'Akuma no Monshou']);
});

test('retarget { animeId }: one linked show becomes that show; several are a 422 naming them; unknown anime is a 422', function () {
    demonsCrestFeed();
    $preview = nyaaPreview();
    $anime = demonsCrestAnime();
    $first = metadataShow('Akuma no Monshou');
    linkShow($first, $anime);

    expect(retargetSeries($preview, ['animeId' => $anime->id])->assertOk()->json('series.0.target'))
        ->toMatchArray(['kind' => 'existing', 'showId' => $first->id, 'showName' => 'Akuma no Monshou', 'anime' => null]);

    $second = metadataShow('Demons Crest (Uncut)');
    linkShow($second, $anime);

    retargetSeries($preview, ['animeId' => $anime->id])
        ->assertStatus(422)
        ->assertJsonPath('message', "Devils' Crest already has shows Akuma no Monshou, Demons Crest (Uncut): pick one of them.")
        ->assertJsonValidationErrors(['target.animeId']);

    retargetSeries($preview, ['animeId' => 999999])
        ->assertStatus(422)
        ->assertJsonPath('message', 'There is no anime with id 999999.');

    retargetSeries($preview, ['animeId' => $anime->id, 'showId' => $first->id])->assertStatus(422);
    retargetSeries($preview, ['animeId' => metadataAnime(['title_romaji' => 'No Show Yet'])->id, 'newName' => str_repeat('x', 256)])->assertStatus(422)
        ->assertJsonPath('message', 'A new show needs a name of 1 to 255 characters.');

    // A blank name arrives as null (the request middleware), so it means the suggested name.
    expect(retargetSeries($preview, ['animeId' => metadataAnime(['title_romaji' => 'No Show Yet 2'])->id, 'newName' => '   '])->assertOk()->json('series.0.target'))
        ->toMatchArray(['kind' => 'new', 'name' => 'Demons Crest']);
});

test('retarget { animeId }: a show with that name and no link is the target, remembering the anime; one linked elsewhere is a 422', function () {
    demonsCrestFeed();
    $preview = nyaaPreview();
    $anime = demonsCrestAnime();
    $unlinked = metadataShow('Demons Crest');

    expect(retargetSeries($preview, ['animeId' => $anime->id])->assertOk()->json('series.0.target'))
        ->toMatchArray(['kind' => 'existing', 'showId' => $unlinked->id, 'anime' => demonsCrestRef($anime)]);

    linkShow($unlinked, metadataAnime(['title_romaji' => 'Something Else']));

    retargetSeries($preview, ['animeId' => $anime->id])
        ->assertStatus(422)
        ->assertJsonPath('message', 'A show named Demons Crest already exists and is linked to Something Else.')
        ->assertJsonValidationErrors(['target.newName']);
});

// ── Search ───────────────────────────────────────────────────────────────────

/**
 * A provider search answer holding just these Media entries.
 *
 * @param  array<int, array<string, mixed>>  $media
 */
function anilistSearchAnswer(array $media): array
{
    return ['data' => ['Page' => ['media' => array_map(fn (array $m) => ['synonyms' => [], 'genres' => [], 'isAdult' => false, ...$m], $media)]]];
}

test('search: local anime with their linked shows (rejected links left out), best match then newest first; no provider call', function () {
    $anime = demonsCrestAnime();
    $older = metadataAnime(['title_romaji' => 'Demons Crest', 'season_year' => 2019]);
    $loose = metadataAnime(['title_romaji' => 'Demons Crest of the Abyss', 'season_year' => 2027]);
    metadataAnime(['title_romaji' => 'Unrelated']);
    $show = metadataShow('Akuma no Monshou');
    linkShow($show, $anime);
    $rejected = metadataShow('Wrong Crest');
    linkShow($rejected, $older);
    ShowAnimeRejection::create(['show_id' => $rejected->id, 'anime_id' => $older->id, 'rejected_at' => now()]);

    $response = $this->getJson('/import/nyaa/shows?q=demons%20crest')->assertOk();

    expect($response->json('providerSearched'))->toBeFalse()
        ->and($response->json('providerError'))->toBeNull()
        ->and($response->json('shows'))->toBe([])
        ->and($response->json('anime.0'))->toBe([...demonsCrestRef($anime), 'linkedShows' => [['id' => $show->id, 'name' => 'Akuma no Monshou']]])
        ->and(array_column($response->json('anime'), 'id'))->toBe([$anime->id, $older->id, $loose->id])
        ->and($response->json('anime.1.linkedShows'))->toBe([]);

    expect($this->getJson('/import/nyaa/shows?q=')->assertOk()->json())->toBe(['shows' => [], 'anime' => [], 'providerSearched' => false, 'providerError' => null])
        ->and($this->getJson('/import/nyaa/shows?q=S2')->json('anime'))->toBe([]);

    Http::assertNothingSent();
});

test('search: at most 10 local anime', function () {
    foreach (range(1, 12) as $i) {
        metadataAnime(['title_romaji' => "Demons Crest {$i}"]);
    }

    expect($this->getJson('/import/nyaa/shows?q=demons')->json('anime'))->toHaveCount(10);
});

test('search provider=1: one provider search, its results stored and listed with the local ones', function () {
    Queue::fake();
    $local = demonsCrestAnime();
    Http::fake(['graphql.anilist.co' => Http::response(anilistSearchAnswer([
        ['id' => 501, 'title' => ['romaji' => 'Demons Crest Zero', 'english' => null], 'seasonYear' => 2027, 'format' => 'TV'],
    ]))]);

    $response = $this->getJson('/import/nyaa/shows?q=Demons%20Crest&provider=1')->assertOk();

    $stored = Anime::where('title_romaji', 'Demons Crest Zero')->sole();

    expect($response->json('providerSearched'))->toBeTrue()
        ->and($response->json('providerError'))->toBeNull()
        ->and(array_column($response->json('anime'), 'id'))->toBe([$local->id, $stored->id])
        ->and($response->json('anime.1'))->toMatchArray(['title' => 'Demons Crest Zero', 'linkedShows' => []]);

    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $request) => json_decode($request->body(), true)['variables']['search'] === 'Demons Crest');
});

test('search provider=1: a provider failure fills providerError and still returns the local anime', function () {
    $local = demonsCrestAnime();
    Http::fake(['graphql.anilist.co' => Http::response('<html>429</html>', 429, ['Retry-After' => '30'])]);

    $response = $this->getJson('/import/nyaa/shows?q=Demons%20Crest&provider=1')->assertOk();

    expect($response->json('providerSearched'))->toBeTrue()
        ->and($response->json('providerError'))->toBe('Online search is busy, try again in a minute.')
        ->and(array_column($response->json('anime'), 'id'))->toBe([$local->id]);

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('search provider=1: an unreachable provider or an error answer get neutral messages too', function () {
    demonsCrestAnime();
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->pushResponse(Http::response(['errors' => [['message' => 'Internal Server Error']]], 500))
        ->whenEmpty(fn () => throw new ConnectionException('Could not resolve host'))]);

    expect($this->getJson('/import/nyaa/shows?q=Demons%20Crest&provider=1')->json('providerError'))->toBe('Online search failed, try again later.')
        ->and($this->getJson('/import/nyaa/shows?q=Demons%20Crest&provider=1')->json('providerError'))->toBe("Online search couldn't be reached, try again later.");
});

test('search provider=1 is limited (10 a minute by default): over it, the local results still come back, quietly; the local search is not limited', function () {
    expect(config('subtracker.nyaa.provider_searches_per_minute'))->toBe(10);
    config(['subtracker.nyaa.provider_searches_per_minute' => 2]);
    $local = metadataAnime(['title_romaji' => 'Ao Ashi']);
    Http::fake(['graphql.anilist.co' => Http::response(anilistSearchAnswer([]))]);

    $this->getJson('/import/nyaa/shows?q=a&provider=1')->assertOk()->assertJsonPath('providerSearched', true);
    $this->getJson('/import/nyaa/shows?q=a&provider=1')->assertOk()->assertJsonPath('providerSearched', true);

    $over = $this->getJson('/import/nyaa/shows?q=ao%20ashi&provider=1')->assertOk();

    expect($over->json('providerSearched'))->toBeFalse()
        ->and($over->json('providerError'))->toBe('Online search is busy, try again in a minute.')
        ->and(array_column($over->json('anime'), 'id'))->toBe([$local->id]);

    // A minute later it's free again.
    $this->travel(61)->seconds();
    $this->getJson('/import/nyaa/shows?q=a&provider=1')->assertJsonPath('providerSearched', true)->assertJsonPath('providerError', null);

    foreach (range(1, 5) as $i) {
        $this->getJson('/import/nyaa/shows?q=a')->assertOk();
    }

    Http::assertSentCount(3);
});

test('search: anime shown without a stored cover get it queued (local and provider-added); a failed or URL-less cover is not asked for again', function () {
    $missing = metadataAnime(['title_romaji' => 'Demons Crest', 'cover_url' => 'https://img.example/dc.jpg']);
    $stored = metadataAnime(['title_romaji' => 'Demons Crest Stored', 'cover_url' => 'https://img.example/st.jpg']);
    AnimeImage::create(['anime_id' => $stored->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => '', 'size' => 0, 'sha256' => str_repeat('a', 64), 'fetched_at' => now()]);
    metadataAnime(['title_romaji' => 'Demons Crest Failed', 'cover_url' => 'https://img.example/f.jpg', 'cover_error' => 'Not an image', 'cover_error_at' => now()]);
    metadataAnime(['title_romaji' => 'Demons Crest No Url']);
    Http::fake(['graphql.anilist.co' => Http::response(anilistSearchAnswer([
        ['id' => 777, 'title' => ['romaji' => 'Demons Crest Online'], 'coverImage' => ['extraLarge' => 'https://img.example/online.jpg']],
    ]))]);

    $this->getJson('/import/nyaa/shows?q=demons%20crest&provider=1')->assertOk()->assertJsonCount(5, 'anime');

    $online = Anime::where('title_romaji', 'Demons Crest Online')->sole();
    $queued = [];
    Queue::assertPushed(FetchAnimeCover::class, function (FetchAnimeCover $job) use (&$queued) {
        $queued[] = $job->animeId;

        return true;
    });

    expect(collect($queued)->unique()->sort()->values()->all())->toBe(collect([$missing->id, $online->id])->sort()->values()->all());
});

// ── Confirm ──────────────────────────────────────────────────────────────────

test('confirm: a new target with an anime creates the show with a manual link (100) and queues the cover', function () {
    Queue::fake();
    Event::fake([ShowDiscovered::class]);
    $anime = demonsCrestAnime();
    demonsCrestFeed();
    $preview = nyaaPreview();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '2']])->assertOk()->json();

    $show = Show::where('name', 'Demons Crest')->sole();
    $link = ShowAnimeLink::where('show_id', $show->id)->sole();

    expect($result)->toMatchArray(['queued' => 2, 'skipped' => [], 'errors' => [], 'warnings' => []])
        ->and($result['shows'])->toBe([['id' => $show->id, 'name' => 'Demons Crest', 'created' => true, 'linkedAnime' => ['id' => $anime->id, 'title' => "Devils' Crest"]]])
        ->and([$link->anime_id, $link->source, $link->confidence])->toBe([$anime->id, LinkSource::Manual, 100])
        ->and(Release::where('show_id', $show->id)->count())->toBe(2);

    Queue::assertPushed(FetchAnimeCover::class, fn (FetchAnimeCover $job) => $job->animeId === $anime->id);
    Event::assertDispatchedTimes(ShowDiscovered::class, 1);
});

test('confirm: the remembered anime links an existing unlinked show of that name', function () {
    Queue::fake();
    $anime = demonsCrestAnime();
    $show = metadataShow('Demons Crest');
    demonsCrestFeed();
    $preview = nyaaPreview();
    retargetSeries($preview, ['animeId' => $anime->id])->assertOk();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1']])->assertOk()->json();

    expect($result['shows'])->toBe([['id' => $show->id, 'name' => 'Demons Crest', 'created' => false, 'linkedAnime' => ['id' => $anime->id, 'title' => "Devils' Crest"]]])
        ->and(ShowAnimeLink::where('show_id', $show->id)->value('source'))->toBe(LinkSource::Manual);
});

test('confirm: an anime that got a show since the preview is still linked, and the result says so', function () {
    Queue::fake();
    $anime = demonsCrestAnime();
    demonsCrestFeed();
    $preview = nyaaPreview();
    expect($preview['series'][0]['target']['anime']['id'])->toBe($anime->id);

    // Meanwhile matching linked another show to it.
    linkShow(metadataShow('Akuma no Monshou'), $anime);

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1']])->assertOk()->json();

    $show = Show::where('name', 'Demons Crest')->sole();

    expect($result['queued'])->toBe(1)
        ->and($result['warnings'])->toBe(["Devils' Crest got another show since the preview (Akuma no Monshou): \"Demons Crest\" is linked to it as well."])
        ->and(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($anime->id)
        ->and(ShowAnimeLink::where('anime_id', $anime->id)->count())->toBe(2);
});

test('confirm: a show of that name linked meanwhile keeps its link; the result says so', function () {
    Queue::fake();
    $anime = demonsCrestAnime();
    demonsCrestFeed();
    $preview = nyaaPreview();

    $other = metadataAnime(['title_romaji' => 'Something Else']);
    $show = metadataShow('Demons Crest');
    linkShow($show, $other);

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1']])->assertOk()->json();

    expect($result['shows'])->toBe([['id' => $show->id, 'name' => 'Demons Crest', 'created' => false, 'linkedAnime' => ['id' => $other->id, 'title' => 'Something Else']]])
        ->and($result['warnings'])->toBe(["\"Demons Crest\" was already linked to another anime, so it wasn't linked to Devils' Crest."]);

    Queue::assertNotPushed(FetchAnimeCover::class);
});

test('confirm: two series creating one show with different anime: the first is linked, the import still succeeds with a warning', function () {
    Queue::fake();
    $first = demonsCrestAnime();
    $second = metadataAnime(['title_romaji' => 'Tank Chair', 'season_year' => 2026]);
    fakeNyaa([
        ['title' => DEMONS_CREST_ERAI, 'id' => 1, 'hash' => hashFor('dc-01')],
        ['title' => '[ToonsHub] TANK CHAIR S01E06 1080p AMZN WEB-DL DUAL DDP5.1 H.265 (TANK CHAIR: Sensha Isu, Dual-Audio, Multi-Subs)', 'id' => 7, 'hash' => hashFor('tc-06')],
    ]);
    $preview = nyaaPreview();
    retargetSeries($preview, ['animeId' => $second->id, 'newName' => 'Demons Crest'], 'tankchair|s1')->assertOk();

    $result = $this->postJson('/import/nyaa/confirm', ['previewId' => $preview['previewId'], 'keys' => ['1', '7']])->assertOk()->json();

    $show = Show::where('name', 'Demons Crest')->sole();

    expect($result['queued'])->toBe(2)
        ->and($result['errors'])->toBe([])
        ->and(ShowAnimeLink::where('show_id', $show->id)->value('anime_id'))->toBe($first->id)
        ->and($result['warnings'])->toBe(['Several series went to "Demons Crest" with different anime: it was linked to the first one only, not to Tank Chair.']);
});
