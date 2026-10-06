<?php

declare(strict_types=1);

namespace App\Enums;

/** Which reconciler fixes ran for a delivery (§17). */
enum FixAttempted: string
{
    case None = 'none';
    case A = 'a';
    case B = 'b';
    case AThenB = 'a_then_b';

    public function withB(): self
    {
        return $this === self::A ? self::AThenB : self::B;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'none',
            self::A => 'fix A (Anime library refresh)',
            self::B => 'fix B (series refresh)',
            self::AThenB => 'fix A (Anime library refresh), then fix B (series refresh)',
        };
    }
}
