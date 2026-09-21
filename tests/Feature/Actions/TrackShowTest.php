<?php

declare(strict_types=1);

use App\Actions\TrackShow;
use App\Enums\RuleState;
use App\Jobs\SyncShowRule;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

test('flips the show to pending and dispatches an async sync job', function () {
    Queue::fake();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => false,
        'rule_state' => RuleState::None,
    ]);

    (new TrackShow)($show);
    $show->refresh();

    expect($show->is_tracked)->toBeTrue()
        ->and($show->tracked_at)->not->toBeNull()
        ->and($show->rule_state)->toBe(RuleState::Pending);

    Queue::assertPushed(SyncShowRule::class, fn ($job) => $job->show->is($show) && $job->track === true);
});
