<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use App\Enums\DispatchStatus;
use App\Models\Anime;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeSuggestion;
use Illuminate\Database\Eloquent\Builder;

/**
 * The show shapes MCP tools return (Torii's SubsPlease side).
 */
final class ShowData
{
    /** Releases get_show includes, newest first. */
    public const RELEASE_LIMIT = 50;

    /**
     * Everything a list row needs, in a fixed number of queries.
     *
     * @param  Builder<Show>  $query
     * @return Builder<Show>
     */
    public static function withRowData(Builder $query): Builder
    {
        return $query
            ->with(['latestRelease', 'animeLink'])
            ->withExists('animeSuggestions')
            ->withCount([
                'releases as queued_count' => fn ($q) => $q->whereIn('dispatch_status', [DispatchStatus::Sent, DispatchStatus::Exists]),
                'releases as downloaded_count' => fn ($q) => $q->whereNotNull('downloaded_at'),
            ]);
    }

    /**
     * Expects withRowData().
     *
     * @return array<string, mixed>
     */
    public static function row(Show $show): array
    {
        $latest = $show->latestRelease;

        return [
            'id' => $show->id,
            'name' => $show->name,
            'isTracked' => $show->is_tracked,
            'ruleState' => $show->rule_state->value,
            'trackingMode' => $show->tracking_mode?->value,
            'latestEpisode' => $latest === null ? null : [
                'episode' => $latest->episode,
                'isBatch' => $latest->is_batch,
                'batchFrom' => $latest->batch_from,
                'batchTo' => $latest->batch_to,
                'publishedAt' => $latest->published_at->toIso8601String(),
            ],
            'queuedCount' => (int) $show->getAttribute('queued_count'),
            'downloadedCount' => (int) $show->getAttribute('downloaded_count'),
            'animeId' => $show->animeLink?->anime_id,
            'hasSuggestions' => (bool) $show->getAttribute('anime_suggestions_exists'),
        ];
    }

    /**
     * Everything get_show returns, loaded here.
     *
     * @return array<string, mixed>
     */
    public static function detail(Show $show): array
    {
        $show = self::withRowData(Show::query())->findOrFail($show->id);

        $releases = $show->releases()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::RELEASE_LIMIT)
            ->get();

        $link = $show->animeLink?->load(['anime' => fn ($q) => $q->select(AnimeData::SUMMARY_COLUMNS)->with(AnimeData::summaryRelations())]);

        $suggestions = ShowAnimeSuggestion::query()
            ->where('show_id', $show->id)
            ->with(['anime' => fn ($q) => $q->select(AnimeData::SUMMARY_COLUMNS)->with(AnimeData::summaryRelations())])
            ->orderByDesc('score')
            ->get();

        return [
            ...self::row($show),
            'ruleError' => $show->rule_error,
            'premieredAt' => $show->premiered_at?->toIso8601String(),
            'releasesTotal' => $show->releases()->count(),
            'releases' => $releases->map(fn (Release $release) => [
                'id' => $release->id,
                'title' => $release->title,
                'episode' => $release->episode,
                'version' => $release->version,
                'isBatch' => $release->is_batch,
                'batchFrom' => $release->batch_from,
                'batchTo' => $release->batch_to,
                'publishedAt' => $release->published_at->toIso8601String(),
                'dispatchStatus' => $release->dispatch_status?->value,
                'dispatchError' => $release->dispatch_error,
                'downloadedAt' => $release->downloaded_at?->toIso8601String(),
            ])->all(),
            'animeLink' => $link === null || $link->anime === null ? null : [
                'anime' => AnimeData::summary($link->anime),
                'linkSource' => $link->source->value,
                'confidence' => $link->confidence,
            ],
            'linkSuggestions' => $suggestions
                ->filter(fn (ShowAnimeSuggestion $s) => $s->anime instanceof Anime)
                ->map(fn (ShowAnimeSuggestion $s) => [
                    'anime' => AnimeData::summary($s->anime),
                    'score' => $s->score,
                    'rule' => $s->rule->value,
                    'reason' => $s->reason->value,
                ])->values()->all(),
        ];
    }
}
