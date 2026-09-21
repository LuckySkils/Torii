<?php

declare(strict_types=1);

namespace App\Services\Feed;

final readonly class ParsedItem
{
    public function __construct(
        public ?string $name,
        public ?string $episode,
        public ?int $version,
        public bool $isBatch,
        public ?string $resolution,
        public ?string $crc,
    ) {}
}
