<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DispatchStatus;
use App\Jobs\QueueReleases;
use App\Models\Release;

/**
 * Queues one release (any, batch included, even if its show isn't tracked),
 * unless it was already sent or found in qBittorrent. Used by the releases list
 * and MCP.
 */
final class DownloadRelease
{
    /** @return bool whether it was queued */
    public function __invoke(Release $release): bool
    {
        if (in_array($release->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true)) {
            return false;
        }

        QueueReleases::dispatch([$release->id]);

        return true;
    }
}
