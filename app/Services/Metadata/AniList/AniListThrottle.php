<?php

declare(strict_types=1);

namespace App\Services\Metadata\AniList;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;

/**
 * A sliding one-minute window shared by every process (web requests and all
 * queue jobs), guarded by a cache lock so concurrent callers can't both take
 * the last slot. A 429 anywhere pauses everyone via blockFor().
 */
final class AniListThrottle
{
    private const WINDOW_KEY = 'anilist:throttle:window';

    private const BLOCKED_UNTIL_KEY = 'anilist:throttle:blocked_until';

    private const LOCK_KEY = 'anilist:throttle:lock';

    /**
     * Takes one request slot, sleeping until one is free. If the wait would be
     * longer than $maxWaitSeconds, throws a rate-limited AniListException carrying
     * the wait instead, so a queued job can release itself rather than sleep.
     */
    public function acquire(?float $maxWaitSeconds = null): void
    {
        while (true) {
            $waitSeconds = Cache::lock(self::LOCK_KEY, 10)->block(10, function (): float {
                $now = $this->now();
                $blockedUntil = (float) Cache::get(self::BLOCKED_UNTIL_KEY, 0);

                if ($blockedUntil > $now) {
                    return $blockedUntil - $now;
                }

                $window = array_values(array_filter(
                    (array) Cache::get(self::WINDOW_KEY, []),
                    fn (float $timestamp) => $timestamp > $now - 60,
                ));

                if (count($window) < $this->limit()) {
                    $window[] = $now;
                    Cache::put(self::WINDOW_KEY, $window, 120);

                    return 0.0;
                }

                return $window[0] + 60 - $now;
            });

            if ($waitSeconds <= 0) {
                return;
            }

            if ($maxWaitSeconds !== null && $waitSeconds > $maxWaitSeconds) {
                throw new AniListException(
                    'AniList request budget exhausted; next slot in '.(int) ceil($waitSeconds).'s.',
                    rateLimited: true,
                    retryAfter: (int) ceil($waitSeconds),
                );
            }

            Sleep::for((int) ceil($waitSeconds * 1000))->milliseconds();
        }
    }

    public function blockFor(int $seconds): void
    {
        Cache::put(self::BLOCKED_UNTIL_KEY, $this->now() + $seconds, $seconds + 60);
    }

    public function limit(): int
    {
        return (int) config('subtracker.metadata.anilist.requests_per_minute');
    }

    private function now(): float
    {
        // Carbon, not microtime(), so Sleep::fake(syncWithCarbon: true) can drive it in tests.
        return now()->getPreciseTimestamp(3) / 1000;
    }
}
