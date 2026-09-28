<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeImage;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
    // The Anime/* pages aren't in the built manifest until the frontend is rebuilt.
    $this->withoutVite();
});

function storeCover(int $animeId): AnimeImage
{
    $bytes = file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_poster.jpg');

    return AnimeImage::create([
        'anime_id' => $animeId,
        'source_url' => 'https://s4.anilist.co/x.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode($bytes),
        'size' => strlen($bytes),
        'width' => 225,
        'height' => 317,
        'sha256' => hash('sha256', $bytes),
        'fetched_at' => now(),
    ]);
}

function linkShowTo(Show $show, Anime $anime, string $source = 'auto', int $confidence = 90): void
{
    ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => $confidence, 'source' => $source, 'linked_at' => now()]);
}

test('the browse page defaults to the current season and pages 30 at a time', function () {
    $summer = metadataAnime(['title_romaji' => 'B Summer Show', 'season' => 'SUMMER', 'season_year' => 2026, 'genres' => ['Comedy', 'Drama']]);
    metadataAnime(['title_romaji' => 'A Fall Show', 'season' => 'FALL', 'season_year' => 2026, 'genres' => ['Action']]);

    $this->get('/anime')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Anime/Index')
        ->has('anime.data', 1)
        ->where('anime.data.0.id', $summer->id)
        ->where('anime.meta.per_page', 30)
        ->where('filters', ['season' => 'SUMMER', 'year' => 2026, 'status' => null, 'genre' => null, 'linked' => 'all'])
        ->where('filterOptions.seasons', ['WINTER', 'SPRING', 'SUMMER', 'FALL'])
        ->where('filterOptions.years', [2026])
        ->where('filterOptions.genres', ['Action', 'Comedy', 'Drama']));
});

test('the browse page filters by season, year, status, genre and linked', function () {
    $linked = metadataAnime(['title_romaji' => 'Linked', 'season' => 'FALL', 'season_year' => 2026, 'status' => 'RELEASING', 'genres' => ['Action']]);
    linkShowTo(metadataShow('Linked Show'), $linked);
    $unlinked = metadataAnime(['title_romaji' => 'Unlinked', 'season' => 'FALL', 'season_year' => 2026, 'status' => 'NOT_YET_RELEASED', 'genres' => ['Drama']]);
    metadataAnime(['title_romaji' => 'Old', 'season' => 'FALL', 'season_year' => 2019, 'genres' => ['Action']]);

    $ids = fn (string $query) => collect($this->get('/anime?'.$query)->viewData('page')['props']['anime']['data'])->pluck('id')->all();

    expect($ids('season=FALL&year=2026'))->toBe([$linked->id, $unlinked->id])
        ->and($ids('season=fall&year=2026&linked=yes'))->toBe([$linked->id])
        ->and($ids('season=FALL&year=2026&linked=no'))->toBe([$unlinked->id])
        ->and($ids('season=&year=&genre=Action'))->toBe([$linked->id, Anime::where('title_romaji', 'Old')->value('id')])
        ->and($ids('season=FALL&year=2026&status=NOT_YET_RELEASED'))->toBe([$unlinked->id]);
});

test('browse entries carry the cover, next airing and linked shows without N+1', function () {
    $anime = metadataAnime(['title_romaji' => 'Airing', 'title_english' => 'Airing EN', 'season' => 'SUMMER', 'season_year' => 2026, 'episodes_total' => 12, 'episodes_aired' => 3, 'format' => 'TV', 'status' => 'RELEASING', 'start_date' => '2026-07-05']);
    $cover = storeCover($anime->id);
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 3, 'airs_at' => '2026-09-21 15:00:00']);
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 5, 'airs_at' => '2026-10-05 15:00:00']);
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 4, 'airs_at' => '2026-09-28 15:00:00']);
    $show = metadataShow('Airing Show');
    linkShowTo($show, $anime);

    $this->get('/anime')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('anime.data.0', [
            'id' => $anime->id,
            'titleRomaji' => 'Airing',
            'titleEnglish' => 'Airing EN',
            'titleNative' => null,
            'coverUrl' => "/anime/{$anime->id}/cover?v=".substr($cover->sha256, 0, 8),
            'coverWidth' => 225,
            'coverHeight' => 317,
            'format' => 'TV',
            'status' => 'RELEASING',
            'season' => 'SUMMER',
            'seasonYear' => 2026,
            'episodesTotal' => 12,
            'episodesAired' => 3,
            'genres' => [],
            'startDate' => '2026-07-05',
            'nextAiringAt' => '2026-09-28T15:00:00+00:00',
            'nextEpisode' => 4,
            'isAdult' => false,
            'linkedShows' => [['id' => $show->id, 'name' => 'Airing Show', 'linkSource' => 'auto', 'confidence' => 90]],
        ]));
});

test('the anime page shows full metadata, airings and linked shows', function () {
    $anime = metadataAnime([
        'title_romaji' => 'Grand Blue 3rd Season',
        'synonyms' => ['Grand Blue S3'],
        'description' => 'Diving <i>and</i> drinking.',
        'duration_minutes' => 24,
        'site_url' => 'https://anilist.co/anime/1',
        'end_date' => '2026-09-20',
    ], anilistId: '555');
    $anime->externalIds()->create(['provider' => 'mal', 'external_id' => '777']);
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 1, 'airs_at' => '2026-07-01 15:00:00']);
    $show = metadataShow('Grand Blue S3', ['is_tracked' => true]);
    linkShowTo($show, $anime, 'manual', 100);

    $this->get("/anime/{$anime->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Anime/Show')
        ->where('anime.id', $anime->id)
        ->where('anime.synonyms', ['Grand Blue S3'])
        ->where('anime.description', 'Diving <i>and</i> drinking.')
        ->where('anime.durationMinutes', 24)
        ->where('anime.endDate', '2026-09-20')
        ->where('anime.siteUrl', 'https://anilist.co/anime/1')
        ->where('anime.externalIds', ['anilist' => '555', 'mal' => '777'])
        ->where('anime.primaryProvider', 'anilist')
        ->where('airings', [['episode' => 1, 'airsAt' => '2026-07-01T15:00:00+00:00', 'isEstimate' => false, 'provider' => 'anilist']])
        ->where('linkedShows.0.id', $show->id)
        ->where('linkedShows.0.isTracked', true)
        ->where('linkedShows.0.linkSource', 'manual')
        ->where('linkedShows.0.confidence', 100)
        ->where('linkedShows.0.imageUrl', null));
});

test('the cover is served from the database with a long-lived ETag, 304 on match, 404 when missing', function () {
    $anime = metadataAnime();
    $image = storeCover($anime->id);

    $this->get("/anime/{$anime->id}/cover")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/jpeg')
        ->assertHeader('ETag', '"'.$image->sha256.'"')
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');

    $this->get("/anime/{$anime->id}/cover", ['If-None-Match' => '"'.$image->sha256.'"'])->assertStatus(304);
    $this->get('/anime/'.metadataAnime()->id.'/cover')->assertNotFound();
});

test('shows index carries the small anime summary, null when unlinked', function () {
    $linked = metadataShow('A Linked');
    metadataShow('B Unlinked');
    $anime = metadataAnime(['title_romaji' => 'Romaji', 'title_english' => 'English', 'episodes_aired' => 7]);
    $cover = storeCover($anime->id);
    linkShowTo($linked, $anime);

    $this->get('/shows')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shows.data.0.anime', [
            'id' => $anime->id,
            'titleRomaji' => 'Romaji',
            'titleEnglish' => 'English',
            'coverUrl' => "/anime/{$anime->id}/cover?v=".substr($cover->sha256, 0, 8),
            'coverWidth' => 225,
            'coverHeight' => 317,
            'episodesAired' => 7,
            'linkSource' => 'auto',
        ])
        ->where('shows.data.1.anime', null));
});

test('the show page carries the full anime object', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime([
        'title_romaji' => 'Grand Blue 3rd Season',
        'title_english' => null,
        'genres' => ['Comedy'],
        'episodes_total' => 12,
        'episodes_aired' => 11,
        'status' => 'RELEASING',
        'format' => 'TV',
        'duration_minutes' => 24,
        'description' => 'Diving <i>and</i> drinking.',
        'season' => 'SUMMER',
        'season_year' => 2026,
        'site_url' => 'https://anilist.co/anime/1',
    ]);
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 12, 'airs_at' => '2026-09-29 15:00:00']);
    linkShowTo($show, $anime, 'auto', 90);

    $this->get("/shows/{$show->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('show.anime', [
            'id' => $anime->id,
            'titleRomaji' => 'Grand Blue 3rd Season',
            'titleEnglish' => null,
            'coverUrl' => null,
            'coverWidth' => null,
            'coverHeight' => null,
            'episodesAired' => 11,
            'linkSource' => 'auto',
            'genres' => ['Comedy'],
            'episodesTotal' => 12,
            'status' => 'RELEASING',
            'format' => 'TV',
            'durationMinutes' => 24,
            'description' => 'Diving <i>and</i> drinking.',
            'season' => 'SUMMER',
            'seasonYear' => 2026,
            'nextAiringAt' => '2026-09-29T15:00:00+00:00',
            'nextEpisode' => 12,
            'siteUrl' => 'https://anilist.co/anime/1',
            'confidence' => 90,
        ]));
});

test('dashboard releases carry the show anime summary', function () {
    $show = metadataShow('Grand Blue S3');
    $anime = metadataAnime(['title_english' => 'Grand Blue Dreaming']);
    linkShowTo($show, $anime);
    Release::create([
        'show_id' => $show->id,
        'guid' => 'G1',
        'title' => '[SubsPlease] Grand Blue S3 - 01 (1080p) [AAAA1111].mkv',
        'episode' => '01',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA1111',
        'published_at' => now()->subHour(),
        'first_seen_at' => now(),
    ]);

    // The dashboard also checks qBittorrent; an unreachable one is fine here.
    Http::fake(fn () => throw new ConnectionException('offline'));

    $this->get('/')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('latestReleases.0.show.anime.id', $anime->id)
        ->where('latestReleases.0.show.anime.titleEnglish', 'Grand Blue Dreaming')
        ->where('latestReleases.0.show.anime.linkSource', 'auto'));
});

test('everything still renders with the metadata layer empty', function () {
    metadataShow('Lonely Show');

    $this->get('/shows')->assertOk();
    $this->get('/anime')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('anime.data', 0));
});
