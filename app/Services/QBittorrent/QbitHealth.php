<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

final readonly class QbitHealth
{
    public function __construct(
        public bool $reachable,
        public ?string $version,
        public ?string $webapi,
        public bool $auth,
        public bool $feed,
        public bool $prefs,
        public bool $category,
    ) {}
}
