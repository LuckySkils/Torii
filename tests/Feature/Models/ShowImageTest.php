<?php

declare(strict_types=1);

use App\Models\Show;
use App\Models\ShowImage;

function imageableShow(): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

test('base64-encoded image bytes round-trip exactly through PostgreSQL, including embedded null bytes', function () {
    $show = imageableShow();

    $bytes = file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_poster.jpg')."\0\0\0binary-null-byte-tail";

    $image = ShowImage::create([
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

    $fromDb = ShowImage::find($image->id);

    expect(base64_decode($fromDb->data))->toBe($bytes)
        ->and(hash('sha256', base64_decode($fromDb->data)))->toBe($image->sha256);
});

test('the data column is hidden from array/JSON serialization', function () {
    $show = imageableShow();

    $image = ShowImage::create([
        'show_id' => $show->id,
        'source_url' => 'https://subsplease.org/poster.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode('some bytes'),
        'size' => 10,
        'width' => 1,
        'height' => 1,
        'sha256' => hash('sha256', 'some bytes'),
        'fetched_at' => now(),
    ]);

    expect($image->toArray())->not->toHaveKey('data');
});
