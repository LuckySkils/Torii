<?php

declare(strict_types=1);

namespace App\Services\Feed;

final readonly class FeedFetchResult
{
    public function __construct(
        public int $httpStatus,
        public bool $notModified,
        public ?string $body,
        public ?string $etag,
        public ?string $lastModified,
    ) {}
}
