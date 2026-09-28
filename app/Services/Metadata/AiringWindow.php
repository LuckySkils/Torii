<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\AnimeAiring;
use Carbon\CarbonInterface;

/**
 * The three airings around now: `current` is the earliest at or after now (the
 * one due next, or airing this very moment), `previous` the latest before now,
 * `next` the one after current. Computed per request, so the window moves on by
 * itself once current's time passes. Any of the three may be null.
 */
final class AiringWindow
{
    /**
     * @return array{previous: array{episode: int, airsAt: string}|null, current: array{episode: int, airsAt: string}|null, next: array{episode: int, airsAt: string}|null}
     */
    public function for(int $animeId, CarbonInterface $now): array
    {
        $previous = AnimeAiring::query()
            ->where('anime_id', $animeId)
            ->where('airs_at', '<', $now)
            ->orderByDesc('airs_at')
            ->orderByDesc('episode')
            ->first(['episode', 'airs_at']);

        $upcoming = AnimeAiring::query()
            ->where('anime_id', $animeId)
            ->where('airs_at', '>=', $now)
            ->orderBy('airs_at')
            ->orderBy('episode')
            ->limit(2)
            ->get(['episode', 'airs_at']);

        return [
            'previous' => $this->entry($previous),
            'current' => $this->entry($upcoming->get(0)),
            'next' => $this->entry($upcoming->get(1)),
        ];
    }

    /**
     * @return array{episode: int, airsAt: string}|null
     */
    private function entry(?AnimeAiring $airing): ?array
    {
        return $airing === null ? null : ['episode' => $airing->episode, 'airsAt' => $airing->airs_at->toIso8601String()];
    }
}
