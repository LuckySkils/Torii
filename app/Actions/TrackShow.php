<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuleState;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use App\Services\Downloads\DownloadPlanner;

final class TrackShow
{
    public function __construct(
        private readonly DownloadPlanner $planner,
    ) {}

    public function __invoke(Show $show): void
    {
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
    }
}
