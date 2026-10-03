<?php

declare(strict_types=1);

namespace App\Actions;

use App\Jobs\QueueReleases;
use App\Models\Show;
use App\Services\Downloads\DownloadPlanner;

/**
 * "Queue missing" (§6.4): re-runs the show's downloadable set and queues what
 * isn't yet sent/exists. No rule changes. Used by the show page and MCP.
 */
final class QueueMissingReleases
{
    public function __construct(private readonly DownloadPlanner $planner) {}

    /**
     * @return array<int, int> the release ids queued (empty: nothing to queue)
     */
    public function __invoke(Show $show): array
    {
        $releaseIds = $this->planner->downloadableSet($show)->pluck('id')->all();

        if ($releaseIds !== []) {
            QueueReleases::dispatch($releaseIds);
        }

        return $releaseIds;
    }
}
