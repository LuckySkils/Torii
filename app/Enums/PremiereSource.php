<?php

declare(strict_types=1);

namespace App\Enums;

enum PremiereSource: string
{
    case SubsPlease = 'subsplease';
    case Episode1 = 'episode1';
    case EarliestSeen = 'earliest_seen';

    /**
     * Higher ranks are better sources; a show's premiere data is only ever
     * overwritten by a result whose source ranks the same or higher.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SubsPlease => 3,
            self::Episode1 => 2,
            self::EarliestSeen => 1,
        };
    }
}
