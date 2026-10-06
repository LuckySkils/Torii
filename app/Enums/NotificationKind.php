<?php

declare(strict_types=1);

namespace App\Enums;

enum NotificationKind: string
{
    case NewEpisode = 'new_episode';
    case Downloaded = 'downloaded';
    case Test = 'test';

    /** The reconciler's automatic fix made a new show's episode playable (§17). */
    case DeliveryFixed = 'delivery_fixed';

    /** The reconciler gave up: a manual Scan All Libraries is needed (§17). */
    case DeliveryGaveUp = 'delivery_gave_up';
}
