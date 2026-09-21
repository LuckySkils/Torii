<?php

declare(strict_types=1);

namespace App\Services\Feed;

final readonly class IngestResult
{
    public function __construct(
        public int $itemsTotal,
        public int $itemsNew,
        public int $showsNew,
    ) {}
}
