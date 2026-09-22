<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\NotificationKind;
use App\Events\NewReleaseDetected;
use App\Jobs\SendNotification;

final class NotifyNewRelease
{
    public function handle(NewReleaseDetected $event): void
    {
        if (! config('subtracker.notifications.enabled') || ! config('subtracker.notifications.notify_new_episode')) {
            return;
        }

        $release = $event->release;

        if ($release->show === null || ! $release->show->is_tracked) {
            return;
        }

        $isRepack = ! $release->is_batch && $release->version !== null && $release->version > 1;

        if ($isRepack && ! config('subtracker.notifications.notify_repacks')) {
            return;
        }

        SendNotification::dispatch(NotificationKind::NewEpisode, $release->id);
    }
}
