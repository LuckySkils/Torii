<?php

declare(strict_types=1);

namespace App\Services\Stats;

use App\Models\FeedPoll;
use App\Models\Release;

final class PollingDelay
{
    /**
     * Median of first_seen_at - published_at over the most recently seen
     * releases, excluding the first poll's backlog (whose delays reflect how old
     * the feed's items were when Torii started, not how fast it polls).
     *
     * @return array{medianSeconds: int|null, sampleSize: int}
     */
    public function recent(int $limit = 50): array
    {
        $firstPollFinishedAt = FeedPoll::query()
            ->whereNull('error')
            ->where('not_modified', false)
            ->orderBy('id')
            ->value('finished_at');

        if ($firstPollFinishedAt === null) {
            return ['medianSeconds' => null, 'sampleSize' => 0];
        }

        $delays = Release::query()
            ->where('first_seen_at', '>', $firstPollFinishedAt)
            ->orderByDesc('first_seen_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['published_at', 'first_seen_at'])
            ->map(fn (Release $release) => (int) $release->published_at->diffInSeconds($release->first_seen_at, false))
            ->sort()
            ->values();

        return ['medianSeconds' => $this->median($delays->all()), 'sampleSize' => $delays->count()];
    }

    /**
     * @param  array<int, int>  $sorted
     */
    private function median(array $sorted): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : intdiv($sorted[$middle - 1] + $sorted[$middle], 2);
    }
}
