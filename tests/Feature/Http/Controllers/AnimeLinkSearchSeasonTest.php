<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\AnimeImage;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    Carbon::setTestNow('2026-09-28 12:00:00');
});

/**
 * A provider search answer holding just these Media entries (the fields the
 * parser needs; the rest of a real Media object is optional to it).
 *
 * @param  array<int, array<string, mixed>>  $media
 */
function providerSearchResponse(array $media): PromiseInterface
{
    return Http::response(['data' => ['Page' => ['media' => array_map(fn (array $m) => [
        'synonyms' => [],
        'genres' => [],
        'isAdult' => false,
        ...$m,
    ], $media)]]]);
}

function providerSearchVariables(): array
{
    $sent = Http::recorded()->first();

    return json_decode($sent[0]->body(), true)['variables'];
}

test('without q, the show name goes to AniList minus its season marker, and the season ranks results', function () {
    $show = metadataShow('Link Click S3');

    Http::fake(['graphql.anilist.co' => providerSearchResponse([
        ['id' => 1, 'title' => ['romaji' => 'Shiguang Dailiren', 'english' => 'Link Click']],
        ['id' => 2, 'title' => ['romaji' => 'Shiguang Dailiren II', 'english' => 'Link Click Season 2']],
        ['id' => 3, 'title' => ['romaji' => 'Shiguang Dailiren III', 'english' => 'Link Click Season 3']],
    ])]);

    $response = $this->getJson("/shows/{$show->id}/link/search")->assertOk();

    expect(providerSearchVariables()['search'])->toBe('Link Click');

    $response->assertJsonPath('query', 'Link Click S3')
        ->assertJsonPath('searchedQuery', 'Link Click')
        ->assertJsonPath('season', 3)
        ->assertJsonPath('providerSearched', true)
        ->assertJsonCount(3, 'results')
        ->assertJsonPath('results.0.titleEnglish', 'Link Click Season 3')
        ->assertJsonPath('results.0.seasonNumber', 3)
        ->assertJsonPath('results.0.score', 90);

    // Other seasons stay listed, just ranked lower.
    expect(array_column(array_slice($response->json('results'), 1), 'seasonNumber'))->toEqualCanonicalizing([1, 2]);
});

test('among results that do not match the show name, the wanted season still comes first', function () {
    $show = metadataShow('Mairimashita! Iruma-kun S4');

    // Different romanization than the show name: all of these score 0 against it.
    Http::fake(['graphql.anilist.co' => providerSearchResponse([
        ['id' => 10, 'title' => ['english' => 'Welcome to Demon School! Iruma-kun'], 'seasonYear' => 2019],
        ['id' => 11, 'title' => ['english' => 'Welcome to Demon School! Iruma-kun Season 4'], 'seasonYear' => 2026],
        ['id' => 12, 'title' => ['english' => 'Welcome to Demon School! Iruma-kun Season 3'], 'seasonYear' => 2022],
    ])]);

    $response = $this->getJson("/shows/{$show->id}/link/search")->assertOk();

    expect(providerSearchVariables()['search'])->toBe('Mairimashita! Iruma-kun');
    $response->assertJsonPath('results.0.titleEnglish', 'Welcome to Demon School! Iruma-kun Season 4')
        ->assertJsonPath('results.0.score', 0);
});

test('a typed query goes to AniList exactly as typed', function () {
    $show = metadataShow('Link Click S3');
    Http::fake(['graphql.anilist.co' => providerSearchResponse([])]);

    $this->getJson("/shows/{$show->id}/link/search?q=".urlencode('Shiguang Dailiren S3'))
        ->assertOk()
        ->assertJsonPath('query', 'Shiguang Dailiren S3')
        ->assertJsonPath('searchedQuery', 'Shiguang Dailiren S3')
        ->assertJsonPath('season', 3);

    expect(providerSearchVariables()['search'])->toBe('Shiguang Dailiren S3');
});

test('plenty of local hits, none in the wanted season, still asks the provider once', function () {
    $show = metadataShow('Link Click S3');
    metadataAnime(['title_english' => 'Link Click']);
    metadataAnime(['title_english' => 'Link Click Season 2']);
    metadataAnime(['title_english' => 'LINK CLICK (Shorts)']);
    metadataAnime(['title_english' => 'LINK CLICK Extra']);
    metadataAnime(['title_english' => 'Link Click: Troubles of Ordinary People']);

    Http::fake(['graphql.anilist.co' => providerSearchResponse([
        ['id' => 3, 'title' => ['romaji' => 'Shiguang Dailiren III', 'english' => 'Link Click Season 3']],
    ])]);

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertOk()
        ->assertJsonPath('providerSearched', true)
        ->assertJsonPath('results.0.titleEnglish', 'Link Click Season 3');

    Http::assertSentCount(1);
    expect(Anime::count())->toBe(6);
});

test('enough local hits including the wanted season stay local', function () {
    $show = metadataShow('Link Click S3');
    metadataAnime(['title_english' => 'Link Click']);
    metadataAnime(['title_english' => 'Link Click Season 2']);
    metadataAnime(['title_english' => 'Link Click Season 3']);
    metadataAnime(['title_english' => 'LINK CLICK (Shorts)']);
    metadataAnime(['title_english' => 'LINK CLICK Extra']);

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertOk()
        ->assertJsonPath('providerSearched', false)
        ->assertJsonPath('results.0.titleEnglish', 'Link Click Season 3');

    Http::assertNothingSent();
});

test('search and suggestion rows carry the cover dimensions', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_romaji' => 'Grand Blue Season 3']);
    AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => '', 'size' => 0, 'width' => 230, 'height' => 326, 'sha256' => str_repeat('a', 64), 'fetched_at' => now()]);
    for ($i = 1; $i <= 5; $i++) {
        metadataAnime(['title_romaji' => "Grand Blue Season 3 Extra {$i}"]);
    }

    $this->getJson("/shows/{$show->id}/link/search")
        ->assertJsonPath('results.0.id', $anime->id)
        ->assertJsonPath('results.0.coverWidth', 230)
        ->assertJsonPath('results.0.coverHeight', 326);
});
