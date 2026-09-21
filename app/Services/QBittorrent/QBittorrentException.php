<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

use RuntimeException;

final class QBittorrentException extends RuntimeException
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $responseBody,
        public readonly ?int $status = null,
    ) {
        parent::__construct("qBittorrent request to [{$endpoint}] failed (status: ".($status ?? 'n/a').'): '.$responseBody);
    }
}
