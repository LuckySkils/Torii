<?php

declare(strict_types=1);

use App\Contracts\DownloadDriver;
use App\Enums\NotificationKind;
use App\Events\NewReleaseDetected;
use App\Jobs\SendNotification;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

function newReleaseListenerShow(bool $tracked): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => $tracked,
    ]);
}

function newReleaseListenerRelease(Show $show, array $overrides = []): Release
{
    static $sequence = 0;
    $sequence++;

    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-NNR-'.$sequence,
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA'.$sequence,
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

beforeEach(function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.notify_new_episode' => true,
        'subtracker.notifications.notify_repacks' => false,
    ]);

    // NewReleaseDetected also runs the existing HandleNewRelease listener, which
    // would otherwise call the real qBittorrent driver for a tracked show.
    $this->mock(DownloadDriver::class, function (MockInterface $mock) {
        $mock->shouldReceive('onNewRelease')->zeroOrMoreTimes();
    });
});

test('dispatches for a tracked show', function () {
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show);

    NewReleaseDetected::dispatch($release);

    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::NewEpisode && $job->releaseId === $release->id);
});

test('does not dispatch for an untracked show', function () {
    Queue::fake();
    $show = newReleaseListenerShow(tracked: false);
    $release = newReleaseListenerRelease($show);

    NewReleaseDetected::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});

test('does not dispatch a v2 repack when repacks are disabled', function () {
    config(['subtracker.notifications.notify_repacks' => false]);
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show, ['version' => 2]);

    NewReleaseDetected::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});

test('dispatches a v2 repack when repacks are enabled', function () {
    config(['subtracker.notifications.notify_repacks' => true]);
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show, ['version' => 2]);

    NewReleaseDetected::dispatch($release);

    Queue::assertPushed(SendNotification::class);
});

test('dispatches exactly one notification for a batch', function () {
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12, 'episode' => null]);

    NewReleaseDetected::dispatch($release);

    Queue::assertPushedTimes(SendNotification::class, 1);
});

test('does not dispatch when notifications are disabled', function () {
    config(['subtracker.notifications.enabled' => false]);
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show);

    NewReleaseDetected::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});

test('does not dispatch when notify_new_episode is off', function () {
    config(['subtracker.notifications.notify_new_episode' => false]);
    Queue::fake();
    $show = newReleaseListenerShow(tracked: true);
    $release = newReleaseListenerRelease($show);

    NewReleaseDetected::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});
