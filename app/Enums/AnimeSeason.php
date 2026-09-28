<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonInterface;

/**
 * Provider-neutral anime seasons, spelled the way AniList (and most sources)
 * spell them. Not to be confused with App\Enums\Season, the premiere season of a
 * SubsPlease show (`autumn`, lowercase).
 */
enum AnimeSeason: string
{
    case Winter = 'WINTER';
    case Spring = 'SPRING';
    case Summer = 'SUMMER';
    case Fall = 'FALL';

    private const TIMEZONE = 'Asia/Tokyo';

    /**
     * @return array{0: self, 1: int}
     */
    public static function forDate(CarbonInterface $date): array
    {
        $tokyo = $date->copy()->setTimezone(self::TIMEZONE);

        $season = match (true) {
            $tokyo->month <= 3 => self::Winter,
            $tokyo->month <= 6 => self::Spring,
            $tokyo->month <= 9 => self::Summer,
            default => self::Fall,
        };

        return [$season, $tokyo->year];
    }

    /**
     * @return array{0: self, 1: int}
     */
    public function next(int $year): array
    {
        return match ($this) {
            self::Winter => [self::Spring, $year],
            self::Spring => [self::Summer, $year],
            self::Summer => [self::Fall, $year],
            self::Fall => [self::Winter, $year + 1],
        };
    }

    /**
     * @return array{0: self, 1: int}
     */
    public function previous(int $year): array
    {
        return match ($this) {
            self::Winter => [self::Fall, $year - 1],
            self::Spring => [self::Winter, $year],
            self::Summer => [self::Spring, $year],
            self::Fall => [self::Summer, $year],
        };
    }

    public static function fromShowSeason(Season $season): self
    {
        return match ($season) {
            Season::Winter => self::Winter,
            Season::Spring => self::Spring,
            Season::Summer => self::Summer,
            Season::Autumn => self::Fall,
        };
    }

    /** A running index (year * 4 + quarter) so seasons can be compared by distance. */
    public static function ordinal(self $season, int $year): int
    {
        return $year * 4 + match ($season) {
            self::Winter => 0,
            self::Spring => 1,
            self::Summer => 2,
            self::Fall => 3,
        };
    }
}
