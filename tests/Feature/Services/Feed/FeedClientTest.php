<?php

declare(strict_types=1);

use App\Services\Feed\FeedClient;
use Illuminate\Support\Facades\Http;

test('fetches the feed and returns the etag and last-modified headers', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('subtracker.feed.url') => Http::response('<rss></rss>', 200, [
            'ETag' => '"abc123"',
            'Last-Modified' => 'Mon, 21 Sep 2026 08:00:00 GMT',
        ]),
    ]);

    $result = (new FeedClient)->fetch();

    expect($result->httpStatus)->toBe(200)
        ->and($result->notModified)->toBeFalse()
        ->and($result->body)->toBe('<rss></rss>')
        ->and($result->etag)->toBe('"abc123"')
        ->and($result->lastModified)->toBe('Mon, 21 Sep 2026 08:00:00 GMT');

    Http::assertSent(function ($request) {
        return $request->hasHeader('User-Agent')
            && ! $request->hasHeader('If-None-Match')
            && ! $request->hasHeader('If-Modified-Since');
    });
});

test('sends conditional headers when an etag and last-modified are known', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('subtracker.feed.url') => Http::response('', 304),
    ]);

    (new FeedClient)->fetch('"abc123"', 'Mon, 21 Sep 2026 08:00:00 GMT');

    Http::assertSent(function ($request) {
        return $request->header('If-None-Match')[0] === '"abc123"'
            && $request->header('If-Modified-Since')[0] === 'Mon, 21 Sep 2026 08:00:00 GMT';
    });
});

test('treats a 304 response as not modified with no body', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('subtracker.feed.url') => Http::response('', 304),
    ]);

    $result = (new FeedClient)->fetch('"abc123"', null);

    expect($result->httpStatus)->toBe(304)
        ->and($result->notModified)->toBeTrue()
        ->and($result->body)->toBeNull();
});
