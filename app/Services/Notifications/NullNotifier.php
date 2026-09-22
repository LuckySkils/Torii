<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifier;

final class NullNotifier implements Notifier
{
    public function send(NotificationMessage $message): void
    {
        // Notifications are disabled; do nothing.
    }
}
