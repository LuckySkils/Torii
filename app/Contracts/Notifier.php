<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Services\Notifications\NotificationMessage;

interface Notifier
{
    public function send(NotificationMessage $message): void;
}
