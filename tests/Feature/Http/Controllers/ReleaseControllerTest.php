<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Jobs\QueueReleases;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Queue;

function downloadableRelease(array $overrides = []): Release
{
    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-DL-'.uniqid(),
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

test('download queues a fresh release and flashes a success message', function () {
    Queue::fake();
    $release = downloadableRelease();

    $response = $this->post("/releases/{$release->id}/download");

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Queued 1 release.');
    Queue::assertPushed(QueueReleases::class, fn ($job) => $job->releaseIds === [$release->id]);
});

test('download does not queue an already-sent release and flashes nothing-to-queue', function () {
    Queue::fake();
    $release = downloadableRelease(['dispatch_status' => DispatchStatus::Sent]);

    $response = $this->post("/releases/{$release->id}/download");

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Nothing to queue.');
    Queue::assertNotPushed(QueueReleases::class);
});

test('download does not queue a release that already exists in qbit', function () {
    Queue::fake();
    $release = downloadableRelease(['dispatch_status' => DispatchStatus::Exists]);

    $response = $this->post("/releases/{$release->id}/download");

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Nothing to queue.');
    Queue::assertNotPushed(QueueReleases::class);
});
