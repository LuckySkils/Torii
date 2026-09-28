<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;
use App\Models\AnimeAiring;
use Carbon\CarbonInterface;

/**
 * Stores provider airings in `anime_airings` and keeps `anime.episodes_aired`
 * derived from them.
 */
final class AnimeAiringWriter
{
    /**
     * Upserts the given airings without touching any others (e.g. the single
     * nextAiringEpisode a season sync sees).
     *
     * @param  array<int, ProviderAiring>  $airings
     */
    public function merge(Anime $anime, string $provider, array $airings): void
    {
        $this->upsert($anime, $provider, $airings);
        $this->refreshEpisodesAired($anime);
    }

    /**
     * Makes the stored schedule match the provider's full schedule: upserts every
     * airing and drops upcoming ones the provider no longer lists (a shortened
     * or rescheduled run). Past airings are history and stay. An empty schedule
     * is taken as "nothing known", not "everything cancelled", and removes nothing.
     *
     * @param  array<int, ProviderAiring>  $airings
     */
    public function replace(Anime $anime, string $provider, array $airings): void
    {
        $this->upsert($anime, $provider, $airings);

        if ($airings !== []) {
            AnimeAiring::query()
                ->where('anime_id', $anime->id)
                ->where('provider', $provider)
                ->where('airs_at', '>', now())
                ->whereNotIn('episode', array_map(fn (ProviderAiring $airing) => $airing->episode, $airings))
                ->delete();
        }

        $this->refreshEpisodesAired($anime);
    }

    public function refreshEpisodesAired(Anime $anime): void
    {
        $episodesAired = $this->episodesAired($anime, now());

        if ($anime->episodes_aired !== $episodesAired) {
            $anime->forceFill(['episodes_aired' => $episodesAired])->save();
        }
    }

    /**
     * The highest episode that has aired, or the next airing episode minus one;
     * when both are known, the larger (each is only a lower bound if the stored
     * schedule has gaps). A finished anime with no schedule falls back to its total.
     */
    public function episodesAired(Anime $anime, CarbonInterface $now): ?int
    {
        $highestAired = AnimeAiring::query()
            ->where('anime_id', $anime->id)
            ->where('airs_at', '<=', $now)
            ->max('episode');

        $nextEpisode = AnimeAiring::query()
            ->where('anime_id', $anime->id)
            ->where('airs_at', '>', $now)
            ->min('episode');

        $candidates = array_filter([
            $highestAired === null ? null : (int) $highestAired,
            $nextEpisode === null ? null : max(0, (int) $nextEpisode - 1),
        ], fn (?int $value) => $value !== null);

        if ($candidates !== []) {
            return max($candidates);
        }

        return $anime->status === 'FINISHED' ? $anime->episodes_total : null;
    }

    /**
     * @param  array<int, ProviderAiring>  $airings
     */
    private function upsert(Anime $anime, string $provider, array $airings): void
    {
        if ($airings === []) {
            return;
        }

        AnimeAiring::upsert(
            array_map(fn (ProviderAiring $airing) => [
                'anime_id' => $anime->id,
                'provider' => $provider,
                'episode' => $airing->episode,
                'airs_at' => $airing->airsAt->utc(),
                'is_estimate' => $airing->isEstimate,
            ], $airings),
            ['anime_id', 'provider', 'episode'],
            ['airs_at', 'is_estimate', 'updated_at'],
        );
    }
}
