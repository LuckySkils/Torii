<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\QueryTermMatcher;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_tags')]
#[Description(<<<'TEXT'
    AniList's genre and tag vocabulary as Torii knows it, for picking exact names to pass in suggest_anime's `tags` (or search_anime's genres_include/genres_exclude, for genres). Each row: name, kind ("genre" or "tag"), category (AniList's tag category, e.g. "Theme-Fantasy", "Setting-Scene", "Cast-Main Cast"; "Genre" for genres; null for a tag not yet in a vocabulary sync), usedInCatalog (how many anime in Torii's catalog have it; spoiler tags not counted, since they never match; 0 means it can't match anything here) and, for tags, isAdult. Ordered by usedInCatalog, most used first, then name. Filter with category and query; every response also lists all categories with their counts, for narrowing. No descriptions in the list: pass describe with exact names to get those entries' descriptions instead. Read-only.
    TEXT)]
#[IsReadOnly]
final class ListTags extends ToriiTool
{
    private const DEFAULT_LIMIT = 50;

    public function schema(JsonSchema $schema): array
    {
        return [
            'category' => $schema->string()->description('Only this category (exact, case-insensitive), e.g. "Theme-Fantasy" or "Genre".'),
            'query' => $schema->string()->description('Only names containing this text, case-insensitive, e.g. "time".'),
            'describe' => $schema->array()->items($schema->string())->description('Exact names (case and punctuation ignored) to describe: returns just those entries, each with its AniList description, plus unknown names. Other filters are ignored.'),
            ...$this->pagingSchema($schema, self::DEFAULT_LIMIT),
        ];
    }

    public function handle(Request $request): Response
    {
        $request->validate([
            'category' => ['nullable', 'string', 'max:100'],
            'query' => ['nullable', 'string', 'max:100'],
            'describe' => ['nullable', 'array', 'max:30'],
            'describe.*' => ['string', 'max:100'],
        ]);

        $vocabulary = $this->vocabulary();

        if (is_array($request->get('describe')) && $request->get('describe') !== []) {
            return $this->json($this->describe($vocabulary, $request->get('describe')));
        }

        $category = trim((string) $request->get('category'));
        $text = trim((string) $request->get('query'));

        $rows = $vocabulary
            ->when($category !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => strcasecmp((string) $row['category'], $category) === 0))
            ->when($text !== '', fn (Collection $rows) => $rows->filter(fn (array $row) => mb_stripos($row['name'], $text) !== false))
            ->values();
        $offset = $this->offset($request);

        return $this->json([
            'total' => $rows->count(),
            'offset' => $offset,
            'categories' => $vocabulary->countBy(fn (array $row) => $row['category'] ?? '(none)')
                ->sortKeys()
                ->map(fn (int $count, string $name) => ['category' => $name, 'count' => $count])
                ->values()
                ->all(),
            'results' => $rows->slice($offset, $this->limit($request, self::DEFAULT_LIMIT))
                ->map(fn (array $row) => array_diff_key($row, ['id' => true, 'description' => true]))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $vocabulary
     * @param  array<int, mixed>  $names
     * @return array<string, mixed>
     */
    private function describe(Collection $vocabulary, array $names): array
    {
        $found = app(QueryTermMatcher::class)->lookup(array_map('strval', $names));
        $ids = [
            'genre' => array_column($found['genres'], 'id'),
            'tag' => array_column($found['tags'], 'id'),
        ];

        return [
            'results' => $vocabulary
                ->filter(fn (array $row) => in_array($row['id'], $ids[$row['kind']], true))
                ->map(fn (array $row) => array_diff_key($row, ['id' => true]))
                ->values()
                ->all(),
            'unknown' => $found['unknown'],
        ];
    }

    /**
     * Every genre and tag with its usage count, most used first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function vocabulary(): Collection
    {
        $genres = DB::table('genres')
            ->leftJoin('anime_genre', 'anime_genre.genre_id', '=', 'genres.id')
            ->groupBy('genres.id', 'genres.name')
            ->get(['genres.id', 'genres.name', DB::raw('count(anime_genre.anime_id) AS used')])
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'kind' => 'genre',
                'category' => 'Genre',
                'usedInCatalog' => (int) $row->used,
                'description' => null,
            ]);

        $tags = DB::table('tags')
            ->leftJoin('anime_tag', fn ($join) => $join->on('anime_tag.tag_id', '=', 'tags.id')->where('anime_tag.is_spoiler', false))
            ->groupBy('tags.id', 'tags.name', 'tags.category', 'tags.is_adult', 'tags.description')
            ->get(['tags.id', 'tags.name', 'tags.category', 'tags.is_adult', 'tags.description', DB::raw('count(anime_tag.anime_id) AS used')])
            ->map(fn (object $row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'kind' => 'tag',
                'category' => $row->category,
                'usedInCatalog' => (int) $row->used,
                'isAdult' => (bool) $row->is_adult,
                'description' => $row->description,
            ]);

        return $genres->concat($tags)
            ->sortBy([['usedInCatalog', 'desc'], ['name', 'asc']])
            ->values();
    }
}
