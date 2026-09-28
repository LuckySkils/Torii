<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Enums\AnimeSeason;
use App\Models\Anime;
use Illuminate\Database\Eloquent\Builder;

/**
 * The previous, current and next season, as of now, and the "worth keeping a
 * cover for" set of anime: in the current or next season, or linked to a show.
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

    /**
     * @return Builder<Anime>
     */
    public function coverEligible(): Builder
    {
        [$currentSeason, $currentYear] = $this->current();
        [$nextSeason, $nextYear] = $this->next();

        return Anime::query()
            ->whereNotNull('cover_url')
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $q) => $q->where('season', $currentSeason->value)->where('season_year', $currentYear))
                ->orWhere(fn (Builder $q) => $q->where('season', $nextSeason->value)->where('season_year', $nextYear))
                ->orWhereHas('links'));
    }
}
