<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

final readonly class QbitHealth
{
    /**
     * @param  array<string, string>  $details  why a check is false, keyed reachable/auth/feed/prefs/category:
     *                                          the exception message, or the setting or config value involved
     */
    public function __construct(
        public bool $reachable,
        public ?string $version,
        public ?string $webapi,
        public bool $auth,
        public bool $feed,
        public bool $prefs,
        public bool $category,
        public array $details = [],
    ) {}
}
