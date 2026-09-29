<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\AnimeSeason;

/**
 * The previous, current and next season, as of now.
 */
final class AnimeSeasons
{
    /**
     * @return array{0: AnimeSeason, 1: int}
     */
    public function current(): array
    {
        return AnimeSeason::forDate(now());
    }

    /**
     * @return array{0: AnimeSeason, 1: int}
     */
    public function previous(): array
    {
        [$season, $year] = $this->current();

        return $season->previous($year);
    }

    /**
     * @return array{0: AnimeSeason, 1: int}
     */
    public function next(): array
    {
        [$season, $year] = $this->current();

        return $season->next($year);
    }
}
