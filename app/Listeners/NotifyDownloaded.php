<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Events\ReleaseDownloaded;
use App\Jobs\SendNotification;

final class NotifyDownloaded
{
    public function handle(ReleaseDownloaded $event): void
    {
        if (! config('subtracker.notifications.enabled') || ! config('subtracker.notifications.notify_downloaded')) {
            return;
        }

        SendNotification::dispatch(NotificationKind::Downloaded, $event->release->id);
    }
}
