<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Data\AnimeData;
use App\Models\Anime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_anime')]
#[Description(<<<'TEXT'
    Find anime in Torii's local catalog (AniList metadata synced for recent and current seasons; not all of AniList) by title and structured filters. Use it to look up an id by title, or to browse e.g. "this season's comedies". For "what should I watch / something like X", prefer suggest_anime, which scores similarity and explains it. Rows are compact summaries without descriptions; call get_anime for one title's detail. isLinked means Torii has a SubsPlease show for it (showId), isTracked means that show is downloaded automatically. Results are ordered by season year (newest first), then romaji title. Read-only.
    TEXT)]
#[IsReadOnly]
final class SearchAnime extends ToriiTool
{
    private const DEFAULT_LIMIT = 20;

    public function schema(JsonSchema $schema): array
    {
        return [...$this->animeFilterSchema($schema), ...$this->pagingSchema($schema, self::DEFAULT_LIMIT)];
    }

    public function handle(Request $request): Response
    {
        $query = Anime::query();
        $this->animeFilters($request)->apply($query);
        $total = (clone $query)->count();
        $limit = $this->limit($request, self::DEFAULT_LIMIT);
        $offset = $this->offset($request);

        $rows = $query
            ->select(AnimeData::SUMMARY_COLUMNS)
            ->with(AnimeData::summaryRelations())
            ->orderByRaw('season_year desc nulls last')
            ->orderBy('title_romaji')
            ->orderBy('id')
            ->offset($offset)
            ->limit($limit)
            ->get();

        return $this->json([
            'total' => $total,
            'offset' => $offset,
            'results' => $rows->map(fn (Anime $anime) => AnimeData::summary($anime))->all(),
        ]);
    }
}
