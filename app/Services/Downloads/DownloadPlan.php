<?php

declare(strict_types=1);

namespace App\Services\Downloads;

use App\Enums\TrackingMode;

final readonly class DownloadPlan
{
    /**
     * @param  array<int, int>  $releaseIds
     */
    public function __construct(
        public array $releaseIds,
        public bool $createRule,
        public TrackingMode $trackingMode,
    ) {}
}
