<?php

declare(strict_types=1);

use App\Actions\UntrackShow;
use App\Enums\RuleState;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

test('flips the show to pending and dispatches an async unsync job', function () {
    Queue::fake();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => true,
        'tracked_at' => now()->subDay(),
        'rule_state' => RuleState::Synced,
    ]);

    (new UntrackShow)($show);
    $show->refresh();

    expect($show->is_tracked)->toBeFalse()
        ->and($show->rule_state)->toBe(RuleState::Pending);

    Queue::assertPushed(SyncShowRule::class, fn ($job) => $job->show->is($show) && $job->track === false);
});
