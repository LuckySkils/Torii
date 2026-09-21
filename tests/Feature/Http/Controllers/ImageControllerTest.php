<?php

declare(strict_types=1);

use App\Enums\ImageStatus;
use App\Jobs\FetchShowImage;
use App\Models\Show;
use App\Models\ShowImage;
use Illuminate\Support\Facades\Queue;

function imageControllerShow(array $overrides = []): Show
{
    return Show::create(array_merge([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

function posterFixtureBytes(): string
{
    return file_get_contents(dirname(__DIR__, 3).'/Fixtures/subsplease_poster.jpg');
}

test('serves the stored image with the right headers', function () {
    $show = imageControllerShow();
    $bytes = posterFixtureBytes();

    ShowImage::create([
        'show_id' => $show->id,
        'source_url' => 'https://subsplease.org/poster.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode($bytes),
        'size' => strlen($bytes),
        'width' => 225,
        'height' => 317,
        'sha256' => hash('sha256', $bytes),
        'fetched_at' => now(),
    ]);

    $response = $this->get("/shows/{$show->id}/image");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
    $response->assertHeader('ETag', '"'.hash('sha256', $bytes).'"');
    $response->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    expect($response->getContent())->toBe($bytes);
});

test('returns 304 when If-None-Match matches the stored ETag', function () {
    $show = imageControllerShow();
    $bytes = posterFixtureBytes();
    $sha256 = hash('sha256', $bytes);

    ShowImage::create([
        'show_id' => $show->id,
        'source_url' => 'https://subsplease.org/poster.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode($bytes),
        'size' => strlen($bytes),
        'width' => 225,
        'height' => 317,
        'sha256' => $sha256,
        'fetched_at' => now(),
    ]);

    $response = $this->get("/shows/{$show->id}/image", ['If-None-Match' => '"'.$sha256.'"']);

    $response->assertStatus(304);
});

test('returns 404 when the show has no stored image', function () {
    $show = imageControllerShow();

    $this->get("/shows/{$show->id}/image")->assertNotFound();
});

test('manual reload dispatches a forced fetch and flashes a message', function () {
    Queue::fake();
    $show = imageControllerShow();

    $response = $this->post("/shows/{$show->id}/image/refresh");

    $response->assertRedirect();
    $response->assertSessionHas('success');
    Queue::assertPushed(FetchShowImage::class, fn ($job) => $job->showId === $show->id && $job->force === true);
});

test('refresh-missing dispatches for shows needing a check and skips shows already found', function () {
    Queue::fake();

    $needsFetch = imageControllerShow(['name' => 'Needs Fetch', 'slug' => 'needs-fetch']);
    $alreadyFound = imageControllerShow(['name' => 'Already Found', 'slug' => 'already-found', 'image_status' => ImageStatus::Found]);

    $response = $this->post('/images/refresh-missing');

    $response->assertRedirect();
    $response->assertSessionHas('success');
    Queue::assertPushed(FetchShowImage::class, 1);
    Queue::assertPushed(FetchShowImage::class, fn ($job) => $job->showId === $needsFetch->id);

    expect($alreadyFound)->not->toBeNull();
});
