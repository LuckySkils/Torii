<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;

final readonly class AnimeSyncResult
{
    public function __construct(
        public Anime $anime,
        public bool $created,
        /** An existing entry whose cover URL changed, so the stored cover is stale. */
        public bool $coverChanged = false,
    ) {}
}
