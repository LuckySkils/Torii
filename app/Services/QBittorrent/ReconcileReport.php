<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

final readonly class ReconcileReport
{
    /**
     * @param  array<int, string>  $created  Rule names created for tracked shows that had no rule yet.
     * @param  array<int, string>  $updated  Rule names updated because they drifted from the expected definition.
     * @param  array<int, string>  $disabled  Rule names disabled because their show is no longer tracked.
     * @param  array<int, string>  $orphaned  Prefixed rule names with no matching show, left untouched.
     */
    public function __construct(
        public array $created = [],
        public array $updated = [],
        public array $disabled = [],
        public array $orphaned = [],
    ) {}
}
