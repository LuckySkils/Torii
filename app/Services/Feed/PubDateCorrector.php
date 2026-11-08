<?php

declare(strict_types=1);

namespace App\Services\Feed;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use Throwable;

/**
 * SubsPlease's RSS pubDate is a wall-clock time labelled "+0000". The clock is
 * really US Pacific time, daylight saving included (−7 h in summer, −8 h from the
 * first Sunday of November), so the label is wrong by that much.
 *
 * Two modes:
 * - timezone (FEED_PUBDATE_TIMEZONE, default America/Los_Angeles): the raw wall
 *   clock is read as local time in that zone and converted to UTC. A wall time
 *   that is ambiguous (DST end: 01:00–01:59 happens twice) or nonexistent (DST
 *   start: 02:00–02:59 never happens) uses the offset in effect *before* the
 *   transition: the earlier occurrence (PDT) for an ambiguous one, and e.g. 02:30
 *   → 10:30Z (03:30 PDT) for a nonexistent one. Chosen explicitly, not left to
 *   PHP's defaults (which happen to agree on PHP 8.4).
 * - offset (FEED_PUBDATE_TIMEZONE empty or "none", or an unknown zone, which also
 *   logs a warning): published_at = parsed pubDate − FEED_PUBDATE_OFFSET_MINUTES.
 *
 * In both modes a raw string whose offset isn't zero is trusted as written: it
 * means SubsPlease has started labelling its times properly.
 */
final class PubDateCorrector
{
    /** RFC 2822, the format the feed itself uses. */
    public const RAW_FORMAT = 'D, d M Y H:i:s O';

    /**
     * Invalid zone names already warned about in this process.
     *
     * @var array<string, true>
     */
    private static array $warnedZones = [];

    public function offsetMinutes(): int
    {
        return (int) config('subtracker.feed.pubdate_offset_minutes');
    }

    /**
     * The zone the feed's wall clock is read in, or null in offset mode.
     */
    public function timezone(): ?DateTimeZone
    {
        $name = trim((string) config('subtracker.feed.pubdate_timezone'));

        if ($name === '' || strtolower($name) === 'none') {
            return null;
        }

        try {
            return new DateTimeZone($name);
        } catch (Throwable) {
            if (! isset(self::$warnedZones[$name])) {
                self::$warnedZones[$name] = true;
                logger()->warning('FEED_PUBDATE_TIMEZONE is not a known timezone; using FEED_PUBDATE_OFFSET_MINUTES instead', [
                    'timezone' => $name,
                    'offset_minutes' => $this->offsetMinutes(),
                ]);
            }

            return null;
        }
    }

    /**
     * Whether a correction is configured at all, in whichever mode is in effect.
     */
    public function isActive(): bool
    {
        return $this->timezone() !== null || $this->offsetMinutes() !== 0;
    }

    /**
     * Whether this raw string gets corrected: a correction is active and the string
     * is labelled UTC (any other offset is trusted as written).
     */
    public function appliesTo(string $raw): bool
    {
        return $this->isActive() && Carbon::parse($raw)->getOffset() === 0;
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
        $parsed = Carbon::parse($raw);
        $literal = $parsed->copy()->utc();

        if ($parsed->getOffset() !== 0) {
            return $literal;
        }

        $zone = $this->timezone();
        $corrected = $zone !== null
            ? $this->fromWallClock($parsed->format('Y-m-d H:i:s'), $zone)
            : $literal->copy()->subMinutes($this->offsetMinutes());

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

    /**
     * The UTC instant at which $zone's clocks showed $wallClock (Y-m-d H:i:s).
     * Uses the offset in effect before any transition near it unless that offset
     * doesn't hold at the resulting instant while the later one does; so an
     * ambiguous time gets its earlier occurrence and a nonexistent one is read
     * with the pre-transition offset.
     */
    private function fromWallClock(string $wallClock, DateTimeZone $zone): CarbonInterface
    {
        $asUtc = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $wallClock, 'UTC');

        // No real zone is more than 14 h off UTC, so a day either side of the wall
        // clock read as UTC falls on either side of any transition near it.
        $offsetBefore = $zone->getOffset($asUtc->subDay());
        $offsetAfter = $zone->getOffset($asUtc->addDay());

        $before = $asUtc->subSeconds($offsetBefore);
        $after = $asUtc->subSeconds($offsetAfter);

        $instant = $zone->getOffset($before) !== $offsetBefore && $zone->getOffset($after) === $offsetAfter
            ? $after
            : $before;

        return Carbon::instance($instant)->utc();
    }
}
