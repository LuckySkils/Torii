<?php

declare(strict_types=1);

namespace App\Services\SubsPlease;

use Illuminate\Support\Facades\Cache;

/**
 * Spaces poster fetches that arrive one at a time (ShowDiscovered fires once per
 * new show) the way images:fetch spaces its bulk dispatches, so a first poll
 * discovering 50+ shows doesn't hit SubsPlease's API in a burst. A shared "next
 * free slot" in the cache: the first fetch goes out immediately, each one after
 * it within the same burst SECONDS later than the last.
 */
final class PosterFetchSpacing
{
    /** Gap between poster fetches; images:fetch and "refresh missing" use it too. */
    public const SECONDS = 3;

    private const SLOT_KEY = 'posters:next_slot';

    private const LOCK_KEY = 'posters:next_slot:lock';

    /** Seconds this fetch should wait: 0 when nothing is scheduled ahead of it. */
    public function nextDelay(): int
    {
        return Cache::lock(self::LOCK_KEY, 5)->block(5, function (): int {
            $now = now()->getTimestamp();
            $slot = max($now, (int) Cache::get(self::SLOT_KEY, 0));

            // Kept only while the burst lasts; afterwards the first fetch is immediate again.
            Cache::put(self::SLOT_KEY, $slot + self::SECONDS, $slot + self::SECONDS - $now + 60);

            return $slot - $now;
        });
    }
}
