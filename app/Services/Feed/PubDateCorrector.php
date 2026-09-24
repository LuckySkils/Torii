<?php

declare(strict_types=1);

namespace App\Services\Feed;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * SubsPlease's RSS pubDate is a wall-clock time labelled "+0000" that actually
 * runs FEED_PUBDATE_OFFSET_MINUTES off UTC (-420 at the time of writing).
 */
final class PubDateCorrector
{
    /** RFC 2822, the format the feed itself uses. */
    public const RAW_FORMAT = 'D, d M Y H:i:s O';

    public function offsetMinutes(): int
    {
        return (int) config('subtracker.feed.pubdate_offset_minutes');
    }

    public function literal(string $raw): CarbonInterface
    {
        return Carbon::parse($raw)->utc();
    }

    /**
     * The corrected UTC publish time. Never later than $firstSeenAt: if the
     * correction would put it there, the feed has presumably been fixed upstream,
     * so the literal value is kept and a warning is logged.
     */
    public function correct(string $raw, CarbonInterface $firstSeenAt, string $context = ''): CarbonInterface
    {
        $literal = $this->literal($raw);
        $corrected = $literal->copy()->subMinutes($this->offsetMinutes());

        if ($corrected->greaterThan($firstSeenAt)) {
            logger()->warning('Corrected pubDate would be later than first_seen_at; keeping the literal value', [
                'release' => $context,
                'raw' => $raw,
                'corrected' => $corrected->toIso8601String(),
                'first_seen_at' => $firstSeenAt->toIso8601String(),
            ]);

            return $literal;
        }

        return $corrected;
    }

    /**
     * Rebuilds the feed's raw string from a published_at that was stored literally
     * (every row ingested before published_at_raw existed).
     */
    public function rawFromLiteral(CarbonInterface $literal): string
    {
        return $literal->copy()->utc()->format(self::RAW_FORMAT);
    }
}
