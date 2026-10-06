<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use Carbon\CarbonImmutable;

/**
 * One `<item>` of a Nyaa RSS feed. `infohash` is normalized (40 lowercase hex)
 * or null; `nyaaId` is the number in the view URL.
 */
final readonly class NyaaItem
{
    public function __construct(
        public string $title,
        public string $torrentUrl,
        public string $viewUrl,
        public ?string $nyaaId,
        public ?CarbonImmutable $publishedAt,
        public string $publishedAtRaw,
        public ?string $infohash,
        public ?string $size,
        public int $seeders,
        public int $leechers,
        public int $downloads,
        public bool $trusted,
        public bool $remake,
        public ?string $category,
    ) {}
}
