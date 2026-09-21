<?php

declare(strict_types=1);

use App\Actions\TrackShow;
use App\Enums\RuleState;
use App\Enums\TrackingMode;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

function trackableShow(string $name = 'Grand Blue S3'): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => false,
        'rule_state' => RuleState::None,
    ]);
}

test('with no releases yet, still creates the rule and dispatches no download job', function () {
    Queue::fake();
    $show = trackableShow();

    app(TrackShow::class)($show);
    $show->refresh();

    expect($show->is_tracked)->toBeTrue()
        ->and($show->tracked_at)->not->toBeNull()
        ->and($show->tracking_mode)->toBe(TrackingMode::Rule)
        ->and($show->rule_state)->toBe(RuleState::Pending);

    Queue::assertPushed(SyncShowRule::class, fn ($job) => $job->show->is($show) && $job->track === true);
    Queue::assertNotPushed(QueueReleases::class);
});

test('with only a fully-covering batch, queues the batch, sets tracking_mode batch, and creates no rule', function () {
    Queue::fake();
    $show = trackableShow();

    $batch = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-BATCH',
        'title' => 'batch',
        'is_batch' => true,
        'batch_from' => 1,
        'batch_to' => 12,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    app(TrackShow::class)($show);
    $show->refresh();

    expect($show->is_tracked)->toBeTrue()
        ->and($show->tracking_mode)->toBe(TrackingMode::Batch)
        ->and($show->rule_state)->toBe(RuleState::None);

    Queue::assertPushed(QueueReleases::class, fn ($job) => $job->releaseIds === [$batch->id]);
    Queue::assertNotPushed(SyncShowRule::class);
});

test('with a batch plus a later episode, queues both and still creates a rule', function () {
    Queue::fake();
    $show = trackableShow();

    $batch = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-BATCH',
        'title' => 'batch',
        'is_batch' => true,
        'batch_from' => 1,
        'batch_to' => 12,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);
    $ep13 = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-13',
        'title' => 'ep13',
        'episode' => '13',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    app(TrackShow::class)($show);
    $show->refresh();

    expect($show->tracking_mode)->toBe(TrackingMode::Rule)
        ->and($show->rule_state)->toBe(RuleState::Pending);

    Queue::assertPushed(QueueReleases::class, fn ($job) => in_array($batch->id, $job->releaseIds, true)
        && in_array($ep13->id, $job->releaseIds, true));
    Queue::assertPushed(SyncShowRule::class, fn ($job) => $job->show->is($show) && $job->track === true);
});
