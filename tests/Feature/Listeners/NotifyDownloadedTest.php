<?php

declare(strict_types=1);

use App\Enums\NotificationKind;
use App\Events\ReleaseDownloaded;
use App\Jobs\SendNotification;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

test('dispatches a downloaded notification regardless of tracked status', function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.notify_downloaded' => true,
    ]);
    Queue::fake();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => false,
    ]);

    $release = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-ND-1',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
        'downloaded_at' => now(),
    ]);

    ReleaseDownloaded::dispatch($release);

    Queue::assertPushed(SendNotification::class, fn ($job) => $job->kind === NotificationKind::Downloaded && $job->releaseId === $release->id);
});

test('does not dispatch when notifications are disabled', function () {
    config(['subtracker.notifications.enabled' => false]);
    Queue::fake();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $release = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-ND-2',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    ReleaseDownloaded::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});

test('does not dispatch when notify_downloaded is off', function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.notify_downloaded' => false,
    ]);
    Queue::fake();

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    $release = Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-ND-3',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:CCCC',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    ReleaseDownloaded::dispatch($release);

    Queue::assertNotPushed(SendNotification::class);
});
