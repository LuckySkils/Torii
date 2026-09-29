<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Spaces queued dispatches on a named channel through a shared "next free slot"
 * in the cache, so fetches from different sources (a command, a sync, page views)
 * never burst together. The first dispatch on an idle channel goes out at once;
 * each one after it within the same burst waits $seconds more.
 */
final class DispatchSpacing
{
    /** Seconds this dispatch should wait: 0 when nothing is scheduled ahead of it. */
    public function nextDelay(string $channel, int $seconds): int
    {
        return Cache::lock("spacing:{$channel}:lock", 5)->block(5, function () use ($channel, $seconds): int {
            $now = now()->getTimestamp();
            $slot = max($now, (int) Cache::get("spacing:{$channel}:next", 0));

            // Kept only while the burst lasts; afterwards the next dispatch is immediate again.
            Cache::put("spacing:{$channel}:next", $slot + $seconds, $slot + $seconds - $now + 60);

            return $slot - $now;
        });
    }
}
