<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\RuleState;
use App\Enums\TrackingMode;
use App\Jobs\QueueReleases;
use App\Jobs\SyncShowRule;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $overrides
 */
function writeRelease(Show $show, string $episode, array $overrides = []): Release
{
    static $n = 0;
    $n++;

    return Release::create([
        'show_id' => $show->id, 'guid' => "MCPW-{$n}", 'title' => "[SubsPlease] {$show->name} - {$episode} (1080p) [{$n}].mkv",
        'episode' => $episode, 'is_batch' => false, 'resolution' => '1080p', 'link' => "magnet:?xt=urn:btih:MCPW{$n}",
        'published_at' => now()->subHours(10 - $n % 10), 'first_seen_at' => now(), ...$overrides,
    ]);
}

function expectWriteLogged(string $tool, array $arguments): void
{
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context) => $message === 'MCP write tool called'
        && $context['tool'] === $tool
        && $context['arguments'] == $arguments
        && is_array($context['result']));
}

beforeEach(function () {
    config(['subtracker.mcp.allow_writes' => true]);
    Queue::fake();
    Log::spy();
});

test('track_show runs the TrackShow action: queues the releases, creates the rule, reports it', function () {
    $show = metadataShow('Grand Blue S3', ['rule_state' => RuleState::None]);
    $one = writeRelease($show, '01');
    $two = writeRelease($show, '02');

    $result = mcpTool('track_show', ['show_id' => $show->id]);

    expect($result)->toBe([
        'show' => ['id' => $show->id, 'name' => 'Grand Blue S3'],
        'wasTracked' => false,
        'isTracked' => true,
        'trackingMode' => 'rule',
        'ruleCreated' => true,
        'ruleState' => 'pending',
        'releasesQueued' => 2,
        'queuedEpisodes' => ['01', '02'],
    ]);
    expect($show->fresh())->is_tracked->toBeTrue()->tracking_mode->toBe(TrackingMode::Rule);
    Queue::assertPushed(QueueReleases::class, fn (QueueReleases $job) => $job->releaseIds == [$one->id, $two->id] || $job->releaseIds == [$two->id, $one->id]);
    Queue::assertPushed(SyncShowRule::class, fn (SyncShowRule $job) => $job->show->is($show) && $job->track);
    expectWriteLogged('track_show', ['show_id' => $show->id]);
});

test('track_show on a fully batched show reports batch mode and no rule', function () {
    $show = metadataShow('Finished Show');
    writeRelease($show, '01');
    writeRelease($show, 'batch', ['episode' => null, 'is_batch' => true, 'batch_from' => null, 'batch_to' => null]);

    $result = mcpTool('track_show', ['show_id' => $show->id]);

    expect($result)->toMatchArray(['trackingMode' => 'batch', 'ruleCreated' => false, 'ruleState' => 'none', 'releasesQueued' => 1]);
    Queue::assertNotPushed(SyncShowRule::class);
});

test('untrack_show runs the UntrackShow action and reports the resulting state', function () {
    $show = metadataShow('Tracked', ['is_tracked' => true, 'tracking_mode' => TrackingMode::Rule, 'rule_state' => RuleState::Synced]);

    expect(mcpTool('untrack_show', ['show_id' => $show->id]))->toBe([
        'show' => ['id' => $show->id, 'name' => 'Tracked'],
        'wasTracked' => true,
        'isTracked' => false,
        'ruleState' => 'pending',
    ]);
    Queue::assertPushed(SyncShowRule::class, fn (SyncShowRule $job) => $job->show->is($show) && ! $job->track);
    expectWriteLogged('untrack_show', ['show_id' => $show->id]);
});

test('queue_missing queues only what was never sent, leaving tracking and the rule alone', function () {
    $show = metadataShow('Partial', ['rule_state' => RuleState::None]);
    writeRelease($show, '01', ['dispatch_status' => DispatchStatus::Sent]);
    $missing = writeRelease($show, '02');

    $result = mcpTool('queue_missing', ['show_id' => $show->id]);

    expect($result)->toBe([
        'show' => ['id' => $show->id, 'name' => 'Partial', 'isTracked' => false],
        'releasesQueued' => 1,
        'queuedEpisodes' => ['02'],
    ]);
    expect($show->fresh())->is_tracked->toBeFalse()->rule_state->toBe(RuleState::None);
    Queue::assertPushed(QueueReleases::class, fn (QueueReleases $job) => $job->releaseIds === [$missing->id]);
    Queue::assertNotPushed(SyncShowRule::class);
    expectWriteLogged('queue_missing', ['show_id' => $show->id]);
});

test('queue_missing with nothing missing reports 0 and queues nothing', function () {
    $show = metadataShow('Complete');
    writeRelease($show, '01', ['dispatch_status' => DispatchStatus::Exists]);

    expect(mcpTool('queue_missing', ['show_id' => $show->id])['releasesQueued'])->toBe(0);
    Queue::assertNothingPushed();
});

test('download_release queues one release; an already-sent one is not sent again', function () {
    $show = metadataShow('Single');
    $release = writeRelease($show, '05');
    $sent = writeRelease($show, '06', ['dispatch_status' => DispatchStatus::Sent]);

    expect(mcpTool('download_release', ['release_id' => $release->id]))->toMatchArray([
        'release' => ['id' => $release->id, 'title' => $release->title, 'episode' => '05', 'isBatch' => false, 'show' => ['id' => $show->id, 'name' => 'Single']],
        'queued' => true,
        'dispatchStatus' => null,
    ]);
    Queue::assertPushed(QueueReleases::class, fn (QueueReleases $job) => $job->releaseIds === [$release->id]);

    expect(mcpTool('download_release', ['release_id' => $sent->id]))->toMatchArray(['queued' => false, 'dispatchStatus' => 'sent']);
    Queue::assertPushed(QueueReleases::class, 1);
    expectWriteLogged('download_release', ['release_id' => $release->id]);
});

test('unknown ids are tool errors, logged, with nothing queued', function () {
    expect(mcpToolError('track_show', ['show_id' => 999999]))->toContain('No show with id 999999')
        ->and(mcpToolError('download_release', ['release_id' => 999999]))->toContain('No release with id 999999');

    Queue::assertNothingPushed();
    expectWriteLogged('track_show', ['show_id' => 999999]);
});

test('a missing argument is a validation error, not a crash', function () {
    expect(mcpToolError('queue_missing'))->toContain('show id');
});
