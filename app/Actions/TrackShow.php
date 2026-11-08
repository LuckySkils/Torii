<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuleState;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use App\Services\Downloads\DownloadPlan;
use App\Services\Downloads\DownloadPlanner;

final class TrackShow
{
    public function __construct(
        private readonly DownloadPlanner $planner,
    ) {}

    /**
     * The one place trackability is enforced: an untracked show without a
     * SubsPlease release is refused (nothing changes). Re-tracking an already
     * tracked show is always allowed.
     *
     * @return DownloadPlan what was queued and whether a rule was created, so a
     *                      caller (the MCP track_show tool) can report it
     *
     * @throws ShowNotTrackable
     */
    public function __invoke(Show $show): DownloadPlan
    {
        $trackability = $show->trackability();

        if (! $trackability['trackable']) {
            throw new ShowNotTrackable($show, (string) $trackability['reason']);
        }

        $plan = $this->planner->plan($show);

        $show->update([
            'is_tracked' => true,
            'tracked_at' => now(),
            'tracking_mode' => $plan->trackingMode,
            'rule_state' => $plan->createRule ? RuleState::Pending : RuleState::None,
            'rule_error' => null,
        ]);

        if ($plan->releaseIds !== []) {
            QueueReleases::dispatch($plan->releaseIds);
        }

        if ($plan->createRule) {
            SyncShowRule::dispatch($show, track: true);
        }

        return $plan;
    }
}
