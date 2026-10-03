<?php

declare(strict_types=1);

namespace App\Services\Metadata;

/**
 * One tag on one anime, as a provider ranks it: rank is its relevance to that
 * anime, 0–100.
 */
final readonly class ProviderTag
{
    public function __construct(
        public string $name,
        public int $rank,
        public bool $isSpoiler,
    ) {}
}
