<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\AnimeAiring;
use App\Models\AnimeImage;
use App\Models\ShowAnimeLink;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Carbon::setTestNow('2026-10-05 12:00:00');
});

function cardAnime(array $attributes = []): Anime
{
    return metadataAnime([
        'title_romaji' => 'Grand Blue Season 3',
        'title_english' => 'Grand Blue Dreaming Season 3',
        'format' => 'TV',
        'status' => 'RELEASING',
        'season' => 'SUMMER',
        'season_year' => 2026,
        'episodes_total' => 12,
        'duration_minutes' => 24,
        'description' => 'Diving <i>and</i> drinking.',
        'genres' => ['Comedy', 'Sports'],
        'site_url' => 'https://anilist.co/anime/199111',
        ...$attributes,
    ]);
}

test('the card has the full shape, with the linked show', function () {
    $anime = cardAnime();
    $cover = AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => base64_encode('bytes'), 'size' => 5, 'width' => 460, 'height' => 650, 'sha256' => str_repeat('c', 64), 'fetched_at' => now()]);
    $untracked = metadataShow('A Untracked');
    $tracked = metadataShow('B Tracked', ['is_tracked' => true]);
    foreach ([$untracked, $tracked] as $show) {
        ShowAnimeLink::create(['show_id' => $show->id, 'anime_id' => $anime->id, 'confidence' => 90, 'source' => 'auto', 'linked_at' => now()]);
    }

    $this->getJson("/anime/{$anime->id}/card")->assertOk()->assertExactJson([
        'id' => $anime->id,
        'titleRomaji' => 'Grand Blue Season 3',
        'titleEnglish' => 'Grand Blue Dreaming Season 3',
        'coverUrl' => "/anime/{$anime->id}/cover?v=".substr($cover->sha256, 0, 8),
        'coverWidth' => 460,
        'coverHeight' => 650,
        'format' => 'TV',
        'status' => 'RELEASING',
        'season' => 'SUMMER',
        'seasonYear' => 2026,
        'episodesTotal' => 12,
        'durationMinutes' => 24,
        'description' => 'Diving <i>and</i> drinking.',
        'descriptionTruncated' => false,
        'genres' => ['Comedy', 'Sports'],
        'isAdult' => false,
        'siteUrl' => 'https://anilist.co/anime/199111',
        // A tracked one first, as on the schedule rows.
        'linkedShow' => ['id' => $tracked->id, 'name' => 'B Tracked', 'isTracked' => true],
    ]);
});

test('an unlinked anime without a cover or description has nulls there', function () {
    $anime = cardAnime(['description' => null]);

    $this->getJson("/anime/{$anime->id}/card")
        ->assertOk()
        ->assertJsonPath('linkedShow', null)
        ->assertJsonPath('coverUrl', null)
        ->assertJsonPath('coverWidth', null)
        ->assertJsonPath('description', null)
        ->assertJsonPath('descriptionTruncated', false);
});

test('a long description is cut to about 600 characters, flagged, and never mid-tag', function () {
    $sentence = 'Kohei Kitahara moves to a seaside town to start college and ends up in a diving club. ';
    $long = str_repeat($sentence, 6).'<i>'.str_repeat('Italic words keep going here. ', 5).'</i>'.str_repeat($sentence, 5);
    $anime = cardAnime(['description' => $long]);

    $card = $this->getJson("/anime/{$anime->id}/card")->assertOk()->json();

    expect($card['descriptionTruncated'])->toBeTrue()
        ->and(mb_strlen($card['description']))->toBeLessThanOrEqual(600)
        ->and(mb_strlen($card['description']))->toBeGreaterThan(480)
        ->and($long)->toStartWith($card['description'])
        // No half-written tag at the end, and a clean word break.
        ->and($card['description'])->not->toMatch('/<[^>]*$/')
        ->and($card['description'])->not->toEndWith(' ');
});

test('a cut that would land inside a tag stops before the tag', function () {
    // Put a tag across the 600-character mark.
    $description = str_repeat('a', 595).'<br><br>'.str_repeat('b', 50);
    $anime = cardAnime(['description' => $description]);

    $card = $this->getJson("/anime/{$anime->id}/card")->json();

    expect($card['description'])->toBe(str_repeat('a', 595).'<br>')
        ->and($card['descriptionTruncated'])->toBeTrue();
});

test('a description of exactly 600 characters is not truncated', function () {
    $anime = cardAnime(['description' => str_repeat('x', 600)]);

    expect($this->getJson("/anime/{$anime->id}/card")->json('descriptionTruncated'))->toBeFalse();
});

test('an unknown id is a 404, and so is a non-numeric one', function () {
    $this->getJson('/anime/999999/card')->assertNotFound();
    $this->getJson('/anime/abc/card')->assertNotFound();
});

test('the cover image data is never selected', function () {
    $anime = cardAnime();
    AnimeImage::create(['anime_id' => $anime->id, 'source_url' => 'x', 'mime' => 'image/jpeg', 'data' => base64_encode(str_repeat('x', 5000)), 'size' => 5000, 'width' => 460, 'height' => 650, 'sha256' => str_repeat('d', 64), 'fetched_at' => now()]);

    DB::enableQueryLog();
    $this->getJson("/anime/{$anime->id}/card")->assertOk();
    $imageQueries = array_values(array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, '"anime_images"')));
    DB::disableQueryLog();

    expect($imageQueries)->toHaveCount(1)
        ->and($imageQueries[0])->not->toContain('"data"')
        ->and($imageQueries[0])->not->toContain('*');
});

test('repeated hovers within two minutes are served from the cache without queries', function () {
    $anime = cardAnime();
    $this->getJson("/anime/{$anime->id}/card")->assertOk();

    $anime->update(['title_english' => 'Renamed']);

    DB::enableQueryLog();
    $cached = $this->getJson("/anime/{$anime->id}/card")->json('titleEnglish');
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($cached)->toBe('Grand Blue Dreaming Season 3')
        ->and($queries)->toBe(0);

    $this->travel(3)->minutes();

    expect($this->getJson("/anime/{$anime->id}/card")->json('titleEnglish'))->toBe('Renamed');
});

test('the schedule rows stay small: no description or genres', function () {
    $this->withoutVite();
    $anime = cardAnime();
    AnimeAiring::create(['anime_id' => $anime->id, 'provider' => 'anilist', 'episode' => 5, 'airs_at' => '2026-10-06 15:00:00']);

    $row = $this->get('/schedule')->viewData('page')['props']['airings'][0];

    expect($row['anime'])->not->toHaveKeys(['description', 'genres', 'descriptionTruncated']);
});
