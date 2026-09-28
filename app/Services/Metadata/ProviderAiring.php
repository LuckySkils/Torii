<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Carbon\CarbonImmutable;

final readonly class ProviderAiring
{
    public function __construct(
        public int $episode,
        public CarbonImmutable $airsAt,
        public bool $isEstimate = false,
    ) {}
}
