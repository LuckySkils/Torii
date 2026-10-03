<?php

declare(strict_types=1);

use App\Mcp\Data\AnimeSuggestions;
use App\Models\Anime;
use App\Models\AnimePayload;
use App\Services\Metadata\AniList\AniListMediaParser;
use App\Services\Metadata\AnimeFilters;
use App\Services\Metadata\AnimeSyncer;
use App\Services\Metadata\ProviderTag;
use Illuminate\Support\Facades\DB;

/**
 * Every anime's genre, tag and studio rows, by name, comparable across id changes.
 *
 * @return array<int, string>
 */
function taxonomySnapshot(): array
{
    $genres = DB::table('anime_genre')->join('genres', 'genres.id', '=', 'anime_genre.genre_id')
        ->get(['anime_genre.anime_id', 'genres.name'])
        ->map(fn (object $row) => "genre {$row->anime_id} {$row->name}");
    $tags = DB::table('anime_tag')->join('tags', 'tags.id', '=', 'anime_tag.tag_id')
        ->get(['anime_tag.anime_id', 'tags.name', 'anime_tag.rank', 'anime_tag.is_spoiler'])
        ->map(fn (object $row) => "tag {$row->anime_id} {$row->name} {$row->rank} ".($row->is_spoiler ? 'spoiler' : '-'));
    $studios = DB::table('anime_studio')->join('studios', 'studios.id', '=', 'anime_studio.studio_id')
        ->get(['anime_studio.anime_id', 'studios.name'])
        ->map(fn (object $row) => "studio {$row->anime_id} {$row->name}");

    return $genres->merge($tags)->merge($studios)->sort()->values()->all();
}

/**
 * @return array<string, mixed>
 */
function edgeCaseMedia(): array
{
    $media = anilistMedia('season_fall_2026_extra_fields', 152677);
    $media['id'] = 999001;
    $media['idMal'] = null;
    $media['genres'] = ['Mystery', 'Mystery', '  ', 5, 'Drama'];
    $media['tags'] = [
        ['name' => 'Detective', 'rank' => 40, 'isMediaSpoiler' => false],
        ['name' => 'Detective', 'rank' => 65, 'isMediaSpoiler' => false], // repeated: highest rank kept
        ['name' => 'No Rank', 'isMediaSpoiler' => false],
        ['name' => 'Not Flagged', 'rank' => 30, 'isMediaSpoiler' => null],
        ['name' => '   ', 'rank' => 90],
        ['rank' => 90],
        'not a tag',
    ];
    $media['studios'] = ['nodes' => [['id' => 1, 'name' => 'ENGI'], ['id' => 1, 'name' => 'ENGI'], ['id' => 2, 'name' => '']]];

    return $media;
}

test('the parser reads tags and main studios from a real AniList entry', function () {
    $anime = (new AniListMediaParser)->parse(anilistMedia('season_fall_2026_extra_fields', 143103));

    expect($anime->studios)->toBe(['ufotable'])
        ->and(collect($anime->tags)->firstWhere('name', 'Magic'))->toEqual(new ProviderTag('Magic', 97, false))
        ->and(collect($anime->tags)->firstWhere('name', 'Time Manipulation'))->toEqual(new ProviderTag('Time Manipulation', 92, true))
        ->and($anime->tags)->toHaveCount(count(anilistMedia('season_fall_2026_extra_fields', 143103)['tags']));
});

test('the parser keeps a repeated tag\'s highest rank, defaults a missing rank to 0 and skips unnamed tags and studios', function () {
    $anime = (new AniListMediaParser)->parse(edgeCaseMedia());

    expect($anime->tags)->toEqual([
        new ProviderTag('Detective', 65, false),
        new ProviderTag('No Rank', 0, false),
        new ProviderTag('Not Flagged', 30, false),
    ])->and($anime->studios)->toBe(['ENGI']);
});

test('the sync writes genres, tags and studios next to the payload, and a re-sync replaces them', function () {
    $syncer = app(AnimeSyncer::class);
    $media = anilistMedia('season_fall_2026_extra_fields', 143103);
    $anime = $syncer->upsert((new AniListMediaParser)->parse($media))->anime;

    expect($anime->tags()->count())->toBe(count($media['tags']))
        ->and($anime->studios()->pluck('name')->all())->toBe(['ufotable'])
        ->and(AnimePayload::where('anime_id', $anime->id)->value('payload')['tags'])->toBe($media['tags']);

    $media['genres'] = ['Fantasy'];
    $media['tags'] = [['name' => 'Magic', 'rank' => 50, 'isMediaSpoiler' => false], ['name' => 'Brand New', 'rank' => 70, 'isMediaSpoiler' => false]];
    $media['studios'] = ['nodes' => [['id' => 7, 'name' => 'Studio Seven']]];
    $syncer->upsert((new AniListMediaParser)->parse($media));

    expect(taxonomySnapshot())->toBe([
        "genre {$anime->id} Fantasy",
        "studio {$anime->id} Studio Seven",
        "tag {$anime->id} Brand New 70 -",
        "tag {$anime->id} Magic 50 -",
    ]);
});

test('the migration backfill produces exactly what the sync writes, from primary-provider payloads only', function () {
    $syncer = app(AnimeSyncer::class);
    $parser = new AniListMediaParser;

    foreach ([143103, 152677, 157330] as $id) {
        $syncer->upsert($parser->parse(anilistMedia('season_fall_2026_extra_fields', $id)));
    }
    $edge = $syncer->upsert($parser->parse(edgeCaseMedia()))->anime;
    // A secondary provider's payload is stored but isn't the anime's.
    AnimePayload::create(['anime_id' => $edge->id, 'provider' => 'other', 'fetched_at' => now(), 'payload' => ['tags' => [['name' => 'Elsewhere', 'rank' => 99]]]]);

    $synced = taxonomySnapshot();
    foreach (['anime_genre', 'anime_tag', 'anime_studio', 'genres', 'tags', 'studios'] as $table) {
        DB::table($table)->delete();
    }

    (require database_path('migrations/2026_10_03_000000_create_anime_tags_and_studios_tables.php'))->backfill();
    (require database_path('migrations/2026_10_03_000001_create_anime_genre_tables.php'))->backfill();

    expect(taxonomySnapshot())->toBe($synced)
        ->and($synced)->toContain("tag {$edge->id} Detective 65 -", "tag {$edge->id} No Rank 0 -", "genre {$edge->id} Mystery", "genre {$edge->id} Drama")
        ->and(array_values(array_filter($synced, fn (string $row) => str_starts_with($row, "genre {$edge->id} "))))->toHaveCount(2)
        ->and(implode("\n", $synced))->not->toContain('Elsewhere');

    // Idempotent: a second run adds nothing.
    (require database_path('migrations/2026_10_03_000000_create_anime_tags_and_studios_tables.php'))->backfill();
    (require database_path('migrations/2026_10_03_000001_create_anime_genre_tables.php'))->backfill();
    expect(taxonomySnapshot())->toBe($synced);
});

test('suggest_anime reads genres, tags and studios from the tables, never unpacking JSON', function () {
    $syncer = app(AnimeSyncer::class);
    $parser = new AniListMediaParser;
    $anchor = $syncer->upsert($parser->parse(anilistMedia('season_fall_2026_extra_fields', 143103)))->anime;
    $syncer->upsert($parser->parse(anilistMedia('season_fall_2026_extra_fields', 152677)));

    DB::enableQueryLog();
    app(AnimeSuggestions::class)->suggest(null, [$anchor->id], AnimeFilters::fromArray(['adult' => 'include']), true, 10);
    $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
    DB::disableQueryLog();

    expect($queries)->not->toContain('anime_payloads')
        ->not->toContain('payload')
        ->not->toContain('jsonb_array_elements')
        ->toContain('anime_genre')
        ->toContain('anime_tag');
    expect(Anime::count())->toBe(2);
});
