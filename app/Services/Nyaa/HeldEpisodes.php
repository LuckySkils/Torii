<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Enums\DispatchStatus;
use App\Models\Release;
use Illuminate\Support\Collection;

/**
 * The episodes shows already have (§16 `have_episode`): releases that are
 * downloaded or sent to qBittorrent (sent/exists), from any group. A batch
 * covers the episodes in its range; one without a parsed range covers all.
 * Episode numbers compare by value ("6" = "06"). One query for all shows.
 */
final class HeldEpisodes
{
    /**
     * @param  array<int, Collection<int, Release>>  $byShow
     */
    private function __construct(private readonly array $byShow) {}

    /**
     * @param  array<int, int>  $showIds
     */
    public static function forShows(array $showIds): self
    {
        $showIds = array_values(array_unique(array_filter($showIds)));

        if ($showIds === []) {
            return new self([]);
        }

        $releases = Release::query()
            ->whereIn('show_id', $showIds)
            ->where(fn ($q) => $q->whereNotNull('downloaded_at')->orWhereIn('dispatch_status', [DispatchStatus::Sent, DispatchStatus::Exists]))
            ->get(['id', 'show_id', 'episode', 'is_batch', 'batch_from', 'batch_to', 'release_group', 'source', 'downloaded_at', 'dispatch_status']);

        return new self($releases->groupBy('show_id')->all());
    }

    /**
     * Why the show already has this episode ("Already have episode 06
     * (SubsPlease, downloaded)."), or null. Only numbered episodes count.
     * A downloaded release is named before a sent one, a single before a batch.
     */
    public function reasonFor(int $showId, ?string $episode): ?string
    {
        if ($episode === null || ! is_numeric($episode)) {
            return null;
        }

        $number = (float) $episode;
        $best = null;

        foreach ($this->byShow[$showId] ?? [] as $release) {
            $covers = $release->is_batch
                ? $release->batch_from === null || $release->batch_to === null || ($number >= $release->batch_from && $number <= $release->batch_to)
                : $release->episode !== null && is_numeric($release->episode) && (float) $release->episode === $number;

            if (! $covers) {
                continue;
            }

            $rank = ($release->downloaded_at !== null ? 0 : 2) + ($release->is_batch ? 1 : 0);

            if ($best === null || $rank < $best[0]) {
                $best = [$rank, $release];
            }
        }

        if ($best === null) {
            return null;
        }

        /** @var Release $release */
        $release = $best[1];
        $what = $release->effectiveGroup() ?? 'unknown group';

        if ($release->is_batch) {
            $what .= $release->batch_from !== null && $release->batch_to !== null ? " batch {$release->batch_from}-{$release->batch_to}" : ' batch';
        }

        $status = $release->downloaded_at !== null ? 'downloaded' : 'sent to qBittorrent';

        return "Already have episode {$episode} ({$what}, {$status}).";
    }
}
