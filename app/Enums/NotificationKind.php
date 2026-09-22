<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationKind: string
{
    case NewEpisode = 'new_episode';
    case Downloaded = 'downloaded';
    case Test = 'test';
}
