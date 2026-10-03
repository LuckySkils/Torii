<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Redactor;
use App\Models\Anime;
use App\Services\Metadata\AnimeFilters;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Shared plumbing: paging capped by MCP_MAX_RESULTS, the anime filter
 * parameters, and compact JSON output.
 */
abstract class ToriiTool extends Tool
{
    protected function maxResults(): int
    {
        return (int) config('subtracker.mcp.max_results');
    }

    protected function limit(Request $request, int $default): int
    {
        $limit = is_numeric($request->get('limit')) ? (int) $request->get('limit') : $default;

        return max(1, min($limit, $this->maxResults()));
    }

    protected function offset(Request $request): int
    {
        return max(0, is_numeric($request->get('offset')) ? (int) $request->get('offset') : 0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function json(array $data): Response
    {
        return Response::json(Redactor::clean($data));
    }

    /**
     * @return array<string, mixed>
     */
    protected function pagingSchema(JsonSchema $schema, int $default): array
    {
        return [
            'limit' => $schema->integer()->min(1)->description("Rows to return. Default {$default}; values above the server's MAX_RESULTS cap are reduced to it."),
            'offset' => $schema->integer()->min(0)->description('Rows to skip, for paging. Default 0.'),
        ];
    }

    /**
     * The search_anime filters, shared by list_schedule and suggest_anime.
     *
     * @return array<string, mixed>
     */
    protected function animeFilterSchema(JsonSchema $schema, string $queryDescription = 'Title text. Every word must appear in the romaji, English or native title or a synonym; punctuation and case are ignored.'): array
    {
        return [
            'query' => $schema->string()->description($queryDescription),
            'season' => $schema->string()->enum(['WINTER', 'SPRING', 'SUMMER', 'FALL'])->description('Broadcast season (AniList seasons, Japan time).'),
            'year' => $schema->integer()->description('Season year, e.g. 2026.'),
            'format' => $schema->array()->items($schema->string()->enum(Anime::FORMATS))->description('Any of these formats.'),
            'status' => $schema->string()->enum(['RELEASING', 'FINISHED', 'NOT_YET_RELEASED', 'CANCELLED', 'HIATUS'])->description('AniList airing status.'),
            'genres_include' => $schema->array()->items($schema->string())->description('AniList genres that must all be present, e.g. ["Comedy", "Romance"].'),
            'genres_exclude' => $schema->array()->items($schema->string())->description('AniList genres that must all be absent.'),
            'adult' => $schema->string()->enum(['hide', 'include', 'only'])->description('Adult (hentai) entries. Default hide.'),
            'linked' => $schema->string()->enum(['all', 'linked', 'unlinked'])->description('linked: has a SubsPlease show in Torii (so episodes can be downloaded). Default all.'),
            'tracked' => $schema->string()->enum(['all', 'tracked', 'untracked'])->description('tracked: its linked show is being tracked (downloaded automatically). Default all.'),
        ];
    }

    protected function animeFilters(Request $request): AnimeFilters
    {
        return AnimeFilters::fromArray($request->all());
    }
}
