<?php

declare(strict_types=1);

namespace App\Services\Premiere;

use App\Enums\PremiereSource;
use Carbon\CarbonInterface;

final readonly class PremiereResult
{
    public function __construct(
        public CarbonInterface $premieredAt,
        public PremiereSource $source,
    ) {}
}
