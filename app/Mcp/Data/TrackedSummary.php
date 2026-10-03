<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use App\Models\Release;
use App\Models\Show;
use App\Services\Schedule\ScheduleBuilder;

/**
 * Everything currently tracked, compactly, for "what should I watch" questions:
 * per show, episodes aired (AniList) vs released (SubsPlease) vs downloaded
 * (qBittorrent finished), the next airing, the rule state, and what's pending.
 */
final class TrackedSummary
{
    public function __construct(private readonly ScheduleBuilder $schedule) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $limit): array
    {
        $tracked = Show::query()
            ->where('is_tracked', true)
            ->orderBy('name')
            ->with(['animeLink.anime' => fn ($q) => $q->select(['id', 'title_romaji', 'title_english', 'status', 'episodes_total', 'episodes_aired'])->with('nextAiring')])
            ->withCount(['releases as undownloaded_count' => fn ($q) => $q->whereNull('downloaded_at')])
            ->get(['id', 'name', 'rule_state', 'tracking_mode', 'is_tracked']);

        $ids = $tracked->modelKeys();
        $released = Release::highestEpisodes($ids);
        $downloaded = Release::highestEpisodes($ids, downloadedOnly: true);

        $rows = $tracked->map(function (Show $show) use ($released, $downloaded): array {
            $anime = $show->animeLink?->anime;
            $releasedEpisode = $released[$show->id] ?? null;
            $aired = $anime?->episodes_aired;

            // Unknown when SubsPlease numbers on from an earlier season (Hyakkano's 36 vs 12).
            $comparable = $anime !== null && ! $this->schedule->numberingDiffers($anime, $releasedEpisode);
            $waiting = $comparable && $aired !== null ? max(0, $aired - (int) floor($releasedEpisode ?? 0)) : null;

            return [
                'id' => $show->id,
                'name' => $show->name,
                'anime' => $anime === null ? null : [
                    'id' => $anime->id,
                    'titleRomaji' => $anime->title_romaji,
                    'titleEnglish' => $anime->title_english,
                    'status' => $anime->status,
                    'episodesTotal' => $anime->episodes_total,
                ],
                'episodes' => [
                    'aired' => $aired,
                    'released' => $releasedEpisode,
                    'downloaded' => $downloaded[$show->id] ?? null,
                ],
                'nextAiring' => $anime?->nextAiring === null ? null : [
                    'episode' => $anime->nextAiring->episode,
                    'airsAt' => $anime->nextAiring->airs_at->toIso8601String(),
                ],
                'ruleState' => $show->rule_state->value,
                'trackingMode' => $show->tracking_mode?->value,
                // Aired on AniList but no SubsPlease release yet; null when it can't be told.
                'waitingEpisodes' => $waiting,
                // Releases Torii has seen that qBittorrent hasn't finished.
                'undownloadedReleases' => (int) $show->getAttribute('undownloaded_count'),
            ];
        });

        return [
            'totals' => [
                'trackedShows' => $rows->count(),
                'withWaitingEpisodes' => $rows->filter(fn (array $row) => ($row['waitingEpisodes'] ?? 0) > 0)->count(),
                'withUndownloadedReleases' => $rows->filter(fn (array $row) => $row['undownloadedReleases'] > 0)->count(),
                'airingInNext24Hours' => $rows->filter(fn (array $row) => $row['nextAiring'] !== null && now()->addDay()->greaterThanOrEqualTo($row['nextAiring']['airsAt']))->count(),
            ],
            'truncated' => $rows->count() > $limit,
            'shows' => $rows->take($limit)->values()->all(),
        ];
    }
}
