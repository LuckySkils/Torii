<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\RuleState;
use App\Jobs\SyncShowRule;
use App\Models\Show;

final class UntrackShow
{
    public function __invoke(Show $show): void
    {
        $show->update([
            'is_tracked' => false,
            'rule_state' => RuleState::Pending,
            'rule_error' => null,
        ]);

        SyncShowRule::dispatch($show, track: false);
    }
}
