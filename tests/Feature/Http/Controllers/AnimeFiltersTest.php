<?php

declare(strict_types=1);

use App\Models\Anime;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Models\ShowAnimeSuggestion;
use Carbon\Carbon;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Carbon::setTestNow('2026-09-28 12:00:00');
    $this->withoutVite();
});

/**
 * Four anime in FALL 2026 with overlapping formats and genres.
 *
 * @return array<string, Anime>
 */
function filterCatalog(): array
{
    $fall = ['season' => 'FALL', 'season_year' => 2026];

    return [
        'actionFantasy' => metadataAnime(['title_romaji' => 'A Action Fantasy', 'format' => 'TV', 'genres' => ['Action', 'Fantasy'], ...$fall]),
        'actionComedy' => metadataAnime(['title_romaji' => 'B Action Comedy', 'format' => 'ONA', 'genres' => ['Action', 'Comedy'], ...$fall]),
        'comedyEcchi' => metadataAnime(['title_romaji' => 'C Comedy Ecchi', 'format' => 'TV', 'genres' => ['Comedy', 'Ecchi'], ...$fall]),
        'movie' => metadataAnime(['title_romaji' => 'D Movie Drama', 'format' => 'MOVIE', 'genres' => ['Drama'], ...$fall]),
    ];
}

/**
 * @return array<int, int>
 */
function animeIds(string $query): array
{
    return collect(test()->get('/anime?season=FALL&year=2026&'.$query)->viewData('page')['props']['anime']['data'])->pluck('id')->all();
}

test('format filters to any of the selected formats, as an array or a comma list', function () {
    $c = filterCatalog();

    expect(animeIds('format[]=TV'))->toBe([$c['actionFantasy']->id, $c['comedyEcchi']->id])
        ->and(animeIds('format[]=TV&format[]=MOVIE'))->toBe([$c['actionFantasy']->id, $c['comedyEcchi']->id, $c['movie']->id])
        ->and(animeIds('format=ona,movie'))->toBe([$c['actionComedy']->id, $c['movie']->id])
        // Unknown values are dropped rather than matching nothing.
        ->and(animeIds('format[]=MANGA'))->toHaveCount(4);
});

test('genres_include needs every selected genre', function () {
    $c = filterCatalog();

    expect(animeIds('genres_include[]=Action'))->toBe([$c['actionFantasy']->id, $c['actionComedy']->id])
        ->and(animeIds('genres_include[]=Action&genres_include[]=Comedy'))->toBe([$c['actionComedy']->id])
        ->and(animeIds('genres_include[]=Action&genres_include[]=Drama'))->toBe([]);
});

test('genres_exclude drops anything with any excluded genre', function () {
    $c = filterCatalog();

    expect(animeIds('genres_exclude[]=Ecchi'))->toBe([$c['actionFantasy']->id, $c['actionComedy']->id, $c['movie']->id])
        ->and(animeIds('genres_exclude[]=Ecchi&genres_exclude[]=Fantasy'))->toBe([$c['actionComedy']->id, $c['movie']->id]);
});

test('every filter combines, including search and the older ones, and is reflected back', function () {
    $c = filterCatalog();
    ShowAnimeLink::create(['show_id' => metadataShow('Linked')->id, 'anime_id' => $c['actionComedy']->id, 'confidence' => 100, 'source' => 'manual', 'linked_at' => now()]);

    expect(animeIds('format[]=TV&format[]=ONA&genres_include[]=Action&genres_exclude[]=Fantasy'))->toBe([$c['actionComedy']->id])
        ->and(animeIds('genres_include[]=Comedy&linked=no'))->toBe([$c['comedyEcchi']->id])
        ->and(animeIds('q=comedy&genres_exclude[]=Ecchi'))->toBe([$c['actionComedy']->id])
        // The old single genre= still counts as an include.
        ->and(animeIds('genre=Drama'))->toBe([$c['movie']->id]);

    $this->get('/anime?season=FALL&year=2026&q=action&format[]=tv&genres_include[]=Action&genres_exclude[]=Ecchi')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.q', 'action')
            ->where('filters.format', ['TV'])
            ->where('filters.genresInclude', ['Action'])
            ->where('filters.genresExclude', ['Ecchi'])
            ->where('anime.meta.total', 1)
            // Pagination links keep the filters.
            ->where('anime.links.first', fn (string $url) => str_contains(urldecode($url), 'genres_include[0]=Action')
                && str_contains(urldecode($url), 'format[0]=tv')));
});

test('filter options list formats and genres with counts over every known anime', function () {
    filterCatalog();
    metadataAnime(['format' => 'TV', 'genres' => ['Action'], 'season' => 'WINTER', 'season_year' => 2020]);

    $this->get('/anime?genres_include[]=Drama')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filterOptions.formats', [
            ['value' => 'TV', 'count' => 3],
            ['value' => 'MOVIE', 'count' => 1],
            ['value' => 'ONA', 'count' => 1],
        ])
        ->where('filterOptions.genres', [
            ['value' => 'Action', 'count' => 3],
            ['value' => 'Comedy', 'count' => 2],
            ['value' => 'Drama', 'count' => 1],
            ['value' => 'Ecchi', 'count' => 1],
            ['value' => 'Fantasy', 'count' => 1],
        ]));
});

// ── /shows, through the linked anime ─────────────────────────────────────────

/**
 * @return array<string, Show>
 */
function linkedShowCatalog(): array
{
    $c = filterCatalog();
    $shows = [];

    foreach (['actionFantasy' => 'A Show', 'actionComedy' => 'B Show', 'comedyEcchi' => 'C Show'] as $key => $name) {
        $shows[$key] = metadataShow($name);
        ShowAnimeLink::create(['show_id' => $shows[$key]->id, 'anime_id' => $c[$key]->id, 'confidence' => 100, 'source' => 'auto', 'linked_at' => now()]);
    }

    $shows['unlinked'] = metadataShow('D Unlinked');

    return $shows;
}

/**
 * @return array<int, int>
 */
function showIds(string $query): array
{
    return collect(test()->get('/shows?'.$query)->viewData('page')['props']['shows']['data'])->pluck('id')->all();
}

test('/shows filters by the linked anime: format and include need a link, exclude keeps unlinked shows', function () {
    $s = linkedShowCatalog();

    expect(showIds('format[]=TV'))->toBe([$s['actionFantasy']->id, $s['comedyEcchi']->id])
        ->and(showIds('genres_include[]=Action&genres_include[]=Comedy'))->toBe([$s['actionComedy']->id])
        ->and(showIds('genres_exclude[]=Ecchi'))->toBe([$s['actionFantasy']->id, $s['actionComedy']->id, $s['unlinked']->id]);
});

test('/shows anime filters combine with search, review and tracking, and are reflected back', function () {
    $s = linkedShowCatalog();
    $s['actionComedy']->update(['is_tracked' => true]);
    ShowAnimeSuggestion::create(['show_id' => $s['comedyEcchi']->id, 'anime_id' => metadataAnime()->id, 'score' => 95, 'rule' => 'exact', 'reason' => 'ambiguous', 'created_at' => now()]);

    expect(showIds('genres_include[]=Action&tracked=yes'))->toBe([$s['actionComedy']->id])
        ->and(showIds('genres_include[]=Comedy&review=1'))->toBe([$s['comedyEcchi']->id])
        ->and(showIds('q=A+Show&format[]=TV'))->toBe([$s['actionFantasy']->id]);

    $this->get('/shows?format=tv&genres_include[]=Action&genres_exclude[]=Ecchi')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filters.format', ['TV'])
        ->where('filters.genresInclude', ['Action'])
        ->where('filters.genresExclude', ['Ecchi']));
});

test('/shows filter options count shows whose linked anime has the value', function () {
    linkedShowCatalog();

    $this->get('/shows')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('filterOptions.formats', [['value' => 'TV', 'count' => 2], ['value' => 'ONA', 'count' => 1]])
        ->where('filterOptions.genres', [
            ['value' => 'Action', 'count' => 2],
            ['value' => 'Comedy', 'count' => 2],
            ['value' => 'Ecchi', 'count' => 1],
            ['value' => 'Fantasy', 'count' => 1],
        ]));
});
