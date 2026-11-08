<?php

declare(strict_types=1);

use App\Services\Feed\PubDateCorrector;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/** Corrects $raw with first_seen_at far enough ahead that the safety net never fires. */
function correctedPubDate(string $raw, string $firstSeenAt = '2030-01-01 00:00:00'): string
{
    return (new PubDateCorrector)->correct($raw, Carbon::parse($firstSeenAt, 'UTC'))->toIso8601String();
}

describe('timezone mode (America/Los_Angeles)', function () {
    beforeEach(function () {
        config([
            'subtracker.feed.pubdate_timezone' => 'America/Los_Angeles',
            'subtracker.feed.pubdate_offset_minutes' => -420,
        ]);
    });

    test('summer: the feed runs 7 hours behind UTC', function () {
        expect(correctedPubDate('Sat, 31 Oct 2026 10:00:00 +0000'))->toBe('2026-10-31T17:00:00+00:00');
    });

    test('winter: the real production rows, 8 hours behind UTC', function () {
        // Release 489, first seen 14:39:01 UTC.
        expect(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000', '2026-11-08 14:39:01'))->toBe('2026-11-08T14:36:57+00:00')
            ->and(correctedPubDate('Sat, 07 Nov 2026 17:32:05 +0000', '2026-11-08 01:45:00'))->toBe('2026-11-08T01:32:05+00:00');
    });

    test('around the end of daylight saving on 2026-11-01', function () {
        expect(correctedPubDate('Sun, 01 Nov 2026 00:59:59 +0000'))->toBe('2026-11-01T07:59:59+00:00')
            ->and(correctedPubDate('Sun, 01 Nov 2026 03:00:00 +0000'))->toBe('2026-11-01T11:00:00+00:00');
    });

    test('an ambiguous wall time (01:00–01:59 happens twice) takes its earlier occurrence, PDT', function () {
        expect(correctedPubDate('Sun, 01 Nov 2026 01:30:00 +0000'))->toBe('2026-11-01T08:30:00+00:00')
            ->and(correctedPubDate('Sun, 01 Nov 2026 01:00:00 +0000'))->toBe('2026-11-01T08:00:00+00:00')
            ->and(correctedPubDate('Sun, 01 Nov 2026 01:59:59 +0000'))->toBe('2026-11-01T08:59:59+00:00')
            // 02:00 exists once, in PST.
            ->and(correctedPubDate('Sun, 01 Nov 2026 02:00:00 +0000'))->toBe('2026-11-01T10:00:00+00:00');
    });

    test('a nonexistent wall time (DST start, 02:00–02:59) is read with the pre-transition offset, PST', function () {
        expect(correctedPubDate('Sun, 14 Mar 2027 01:59:59 +0000'))->toBe('2027-03-14T09:59:59+00:00')
            // 02:30 never happens in Los Angeles; read as PST it is 10:30Z, i.e. 03:30 PDT.
            ->and(correctedPubDate('Sun, 14 Mar 2027 02:30:00 +0000'))->toBe('2027-03-14T10:30:00+00:00')
            ->and(correctedPubDate('Sun, 14 Mar 2027 03:00:00 +0000'))->toBe('2027-03-14T10:00:00+00:00');
    });

    test('a raw string with a real offset is trusted as written', function () {
        expect(correctedPubDate('Sun, 08 Nov 2026 06:36:57 -0800'))->toBe('2026-11-08T14:36:57+00:00')
            ->and(correctedPubDate('Sun, 08 Nov 2026 23:36:57 +0900'))->toBe('2026-11-08T14:36:57+00:00')
            ->and((new PubDateCorrector)->appliesTo('Sun, 08 Nov 2026 06:36:57 -0800'))->toBeFalse()
            ->and((new PubDateCorrector)->appliesTo('Sun, 08 Nov 2026 06:36:57 +0000'))->toBeTrue();
    });

    test('the safety net still keeps the literal value when the correction would pass first_seen_at', function () {
        Log::spy();

        // Corrected would be 14:36:57Z, after the 10:00 first sighting.
        expect(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000', '2026-11-08 10:00:00'))->toBe('2026-11-08T06:36:57+00:00');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => str_contains($message, 'later than first_seen_at')
            && $context['corrected'] === '2026-11-08T14:36:57+00:00');
    });

    test('is active even with a zero offset configured', function () {
        config(['subtracker.feed.pubdate_offset_minutes' => 0]);

        expect((new PubDateCorrector)->isActive())->toBeTrue()
            ->and(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000'))->toBe('2026-11-08T14:36:57+00:00');
    });
});

describe('offset mode', function () {
    test('"none" (any case), empty and null use the fixed offset all year', function (?string $zone) {
        config([
            'subtracker.feed.pubdate_timezone' => $zone,
            'subtracker.feed.pubdate_offset_minutes' => -420,
        ]);

        expect((new PubDateCorrector)->timezone())->toBeNull()
            ->and(correctedPubDate('Sat, 31 Oct 2026 10:00:00 +0000'))->toBe('2026-10-31T17:00:00+00:00')
            ->and(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000'))->toBe('2026-11-08T13:36:57+00:00');
    })->with(['none', 'NONE', ' None ', '', null]);

    test('an invalid zone falls back to the offset and logs a warning once', function () {
        Log::spy();
        config([
            'subtracker.feed.pubdate_timezone' => 'Mars/Olympus_Mons',
            'subtracker.feed.pubdate_offset_minutes' => -480,
        ]);

        expect(correctedPubDate('Sat, 31 Oct 2026 10:00:00 +0000'))->toBe('2026-10-31T18:00:00+00:00')
            ->and(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000'))->toBe('2026-11-08T14:36:57+00:00');

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'FEED_PUBDATE_TIMEZONE')
            && $context['timezone'] === 'Mars/Olympus_Mons');
    });

    test('a raw string with a real offset is trusted as written', function () {
        config(['subtracker.feed.pubdate_timezone' => 'none', 'subtracker.feed.pubdate_offset_minutes' => -420]);

        expect(correctedPubDate('Sun, 08 Nov 2026 06:36:57 -0800'))->toBe('2026-11-08T14:36:57+00:00')
            ->and((new PubDateCorrector)->appliesTo('Sun, 08 Nov 2026 06:36:57 -0800'))->toBeFalse();
    });

    test('an offset of 0 is no correction at all', function () {
        config(['subtracker.feed.pubdate_timezone' => 'none', 'subtracker.feed.pubdate_offset_minutes' => 0]);

        expect((new PubDateCorrector)->isActive())->toBeFalse()
            ->and((new PubDateCorrector)->appliesTo('Sun, 08 Nov 2026 06:36:57 +0000'))->toBeFalse()
            ->and(correctedPubDate('Sun, 08 Nov 2026 06:36:57 +0000'))->toBe('2026-11-08T06:36:57+00:00');
    });
});
