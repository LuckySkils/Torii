<?php

declare(strict_types=1);

namespace App\Services\Feed;

use Illuminate\Support\Facades\Http;

final class FeedClient
{
    public function fetch(?string $etag = null, ?string $lastModified = null): FeedFetchResult
    {
        $headers = [
            'User-Agent' => 'Torii-Subtracker/1.0',
        ];

        if ($etag !== null) {
            $headers['If-None-Match'] = $etag;
        }

        if ($lastModified !== null) {
            $headers['If-Modified-Since'] = $lastModified;
        }

        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->retry(2, 100)
            ->get(config('subtracker.feed.url'));

        $notModified = $response->status() === 304;

        return new FeedFetchResult(
            httpStatus: $response->status(),
            notModified: $notModified,
            body: $notModified ? null : $response->body(),
            etag: $response->header('ETag') ?: null,
            lastModified: $response->header('Last-Modified') ?: null,
        );
    }
}
