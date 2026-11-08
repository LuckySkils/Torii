<?php

declare(strict_types=1);

use App\Enums\PremiereSource;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\DB;

function fixableShow(string $name, array $overrides = []): Show
{
    return Show::create(array_merge([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => '2026-09-20 00:00:00',
        'last_seen_at' => '2026-09-21 00:00:00',
    ], $overrides));
}

/**
 * A row as the pre-fix code stored it: literal feed wall clock, no raw string.
 */
function oldStyleRelease(Show $show, string $guid, string $literal, string $firstSeenAt, ?string $episode = '05'): Release
{
    return Release::create([
        'show_id' => $show->id,
        'guid' => $guid,
        'title' => "[SubsPlease] {$show->name} - {$episode} (1080p) [ABCD1234].mkv",
        'episode' => $episode,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$guid,
        'published_at' => $literal,
        'published_at_raw' => null,
        'first_seen_at' => $firstSeenAt,
    ]);
}

/**
 * @return array<int, array{published_at: string, published_at_raw: string|null}>
 */
function publishedSnapshot(): array
{
    return DB::table('releases')->orderBy('id')->get(['id', 'published_at', 'published_at_raw'])
        ->mapWithKeys(fn (object $row) => [$row->id => ['published_at' => $row->published_at, 'published_at_raw' => $row->published_at_raw]])
        ->all();
}

test('is a no-op on a fresh install with no releases', function () {
    $this->artisan('releases:fix-published')
        ->expectsOutputToContain('Checked 0 releases: 0 corrected')
        ->assertSuccessful();

    expect(Release::count())->toBe(0);
});

test('corrects old rows by reconstructing the raw string, and leaves rows ingested after the fix unchanged', function () {
    $show = fixableShow('Test Show');
    $old = oldStyleRelease($show, 'OLD', '2026-09-21 06:33:36', '2026-09-21 14:00:00');

    $new = Release::create([
        'show_id' => $show->id,
        'guid' => 'NEW',
        'title' => 'new',
        'episode' => '06',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:NEW',
        'published_at' => '2026-09-21 16:00:00',
        'published_at_raw' => 'Mon, 21 Sep 2026 09:00:00 +0000',
        'first_seen_at' => '2026-09-21 16:05:00',
    ]);

    $this->artisan('releases:fix-published')->assertSuccessful();

    $old->refresh();
    $new->refresh();

    expect($old->published_at_raw)->toBe('Mon, 21 Sep 2026 06:33:36 +0000')
        ->and($old->published_at->toIso8601String())->toBe('2026-09-21T13:33:36+00:00')
        ->and($new->published_at_raw)->toBe('Mon, 21 Sep 2026 09:00:00 +0000')
        ->and($new->published_at->toIso8601String())->toBe('2026-09-21T16:00:00+00:00');
});

test('running it twice gives the same result as running it once', function () {
    $show = fixableShow('Test Show');
    oldStyleRelease($show, 'A', '2026-09-21 06:33:36', '2026-09-21 14:00:00');
    oldStyleRelease($show, 'B', '2026-09-20 01:00:00', '2026-09-20 09:00:00', '04');

    $this->artisan('releases:fix-published')->assertSuccessful();
    $afterOnce = publishedSnapshot();

    $this->artisan('releases:fix-published')
        ->expectsOutputToContain('Checked 2 releases: 0 corrected, 0 raw values reconstructed, 0 kept literal by the safety net; premiere date changed for 0 shows.')
        ->assertSuccessful();

    expect(publishedSnapshot())->toBe($afterOnce);
});

test('recomputes premiered_at only for episode1 and earliest_seen shows', function () {
    $episode1Show = fixableShow('Episode One Show', [
        'premiered_at' => '2026-09-01 06:00:00',
        'premiere_source' => PremiereSource::Episode1,
    ]);
    oldStyleRelease($episode1Show, 'E1', '2026-09-01 06:00:00', '2026-09-01 14:00:00', '01');

    $earliestShow = fixableShow('Earliest Show', [
        'premiered_at' => '2026-09-02 06:00:00',
        'premiere_source' => PremiereSource::EarliestSeen,
    ]);
    oldStyleRelease($earliestShow, 'ES', '2026-09-02 06:00:00', '2026-09-02 14:00:00', '07');

    $subsPleaseShow = fixableShow('SubsPlease Show', [
        'premiered_at' => '2026-07-01 12:00:00',
        'premiere_source' => PremiereSource::SubsPlease,
    ]);
    oldStyleRelease($subsPleaseShow, 'SP', '2026-09-03 06:00:00', '2026-09-03 14:00:00', '01');

    $this->artisan('releases:fix-published')->assertSuccessful();

    expect($episode1Show->refresh()->premiered_at->toIso8601String())->toBe('2026-09-01T13:00:00+00:00')
        ->and($earliestShow->refresh()->premiered_at->toIso8601String())->toBe('2026-09-02T13:00:00+00:00')
        ->and($subsPleaseShow->refresh()->premiered_at->toIso8601String())->toBe('2026-07-01T12:00:00+00:00')
        ->and($subsPleaseShow->premiere_source)->toBe(PremiereSource::SubsPlease);
});

test('no timezone and an offset of 0 leave every value unchanged', function () {
    config(['subtracker.feed.pubdate_timezone' => 'none', 'subtracker.feed.pubdate_offset_minutes' => 0]);
    $show = fixableShow('Test Show');
    $old = oldStyleRelease($show, 'OLD', '2026-09-21 06:33:36', '2026-09-21 14:00:00');

    $this->artisan('releases:fix-published')
        ->expectsOutputToContain('Checked 1 releases: 0 corrected, 1 raw values reconstructed, 0 kept literal by the safety net')
        ->assertSuccessful();

    expect($old->refresh()->published_at->toIso8601String())->toBe('2026-09-21T06:33:36+00:00');
});

/**
 * A row as the fixed -420 offset stored it: raw string kept, published_at = raw + 7 h.
 */
function offsetCorrectedRelease(Show $show, string $guid, string $raw, string $firstSeenAt, string $episode): Release
{
    return Release::create([
        'show_id' => $show->id,
        'guid' => $guid,
        'source' => 'subsplease_rss',
        'title' => "[SubsPlease] {$show->name} - {$episode} (1080p) [ABCD1234].mkv",
        'episode' => $episode,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$guid,
        'published_at' => Carbon\Carbon::parse($raw)->utc()->addHours(7),
        'published_at_raw' => $raw,
        'first_seen_at' => $firstSeenAt,
    ]);
}

test('in timezone mode one run fixes rows on both sides of the DST change, and a second run changes nothing', function () {
    config(['subtracker.feed.pubdate_timezone' => 'America/Los_Angeles', 'subtracker.feed.pubdate_offset_minutes' => -420]);

    // A show that premiered in winter, its episode 1 stored an hour early by the fixed offset.
    $winterShow = fixableShow('Winter Show', [
        'premiered_at' => '2026-11-07 13:30:00',
        'premiere_source' => PremiereSource::Episode1,
    ]);
    $winterEp1 = offsetCorrectedRelease($winterShow, 'W1', 'Sat, 07 Nov 2026 06:30:00 +0000', '2026-11-07 14:40:00', '01');
    $winterEp2 = offsetCorrectedRelease($winterShow, 'W2', 'Sun, 08 Nov 2026 06:36:57 +0000', '2026-11-08 14:39:01', '02');

    $summerShow = fixableShow('Summer Show');
    $summer = offsetCorrectedRelease($summerShow, 'S1', 'Sat, 31 Oct 2026 10:00:00 +0000', '2026-10-31 17:05:00', '05');
    // Pre-fix row with no raw string, also from summer.
    $legacy = oldStyleRelease($summerShow, 'S0', '2026-09-21 06:33:36', '2026-09-21 14:00:00', '04');

    // A Nyaa import with a pubDate that is already true UTC must be left as it is.
    $nyaa = Release::create([
        'show_id' => $winterShow->id,
        'guid' => 'https://nyaa.si/view/1',
        'source' => 'nyaa',
        'title' => '[SubsPlease] Winter Show - 03 (1080p) [ABCD1234].mkv',
        'episode' => '03',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:N',
        'published_at' => '2026-11-08 06:36:57',
        'published_at_raw' => 'Sun, 08 Nov 2026 06:36:57 -0000',
        'first_seen_at' => '2026-11-08 20:00:00',
    ]);

    $this->artisan('releases:fix-published')
        // Corrected: the two winter rows (an hour later) and the legacy row (literal → +7 h).
        ->expectsOutputToContain('Checked 4 releases: 3 corrected, 1 raw values reconstructed, 0 kept literal by the safety net; premiere date changed for 1 shows.')
        ->assertSuccessful();

    expect($summer->refresh()->published_at->toIso8601String())->toBe('2026-10-31T17:00:00+00:00')
        ->and($legacy->refresh()->published_at->toIso8601String())->toBe('2026-09-21T13:33:36+00:00')
        ->and($winterEp1->refresh()->published_at->toIso8601String())->toBe('2026-11-07T14:30:00+00:00')
        ->and($winterEp2->refresh()->published_at->toIso8601String())->toBe('2026-11-08T14:36:57+00:00')
        ->and($nyaa->refresh()->published_at->toIso8601String())->toBe('2026-11-08T06:36:57+00:00')
        ->and($winterShow->refresh()->premiered_at->toIso8601String())->toBe('2026-11-07T14:30:00+00:00')
        ->and($winterShow->premiere_source)->toBe(PremiereSource::Episode1);

    $afterOnce = publishedSnapshot();

    $this->artisan('releases:fix-published')
        ->expectsOutputToContain('Checked 4 releases: 0 corrected, 0 raw values reconstructed, 0 kept literal by the safety net; premiere date changed for 0 shows.')
        ->assertSuccessful();

    expect(publishedSnapshot())->toBe($afterOnce);
});

test('in timezone mode, rows the safety net keeps literal are counted', function () {
    config(['subtracker.feed.pubdate_timezone' => 'America/Los_Angeles']);
    $show = fixableShow('Test Show');
    // Seen at 10:00, before the corrected 14:36:57.
    offsetCorrectedRelease($show, 'EARLY', 'Sun, 08 Nov 2026 06:36:57 +0000', '2026-11-08 10:00:00', '02');

    $this->artisan('releases:fix-published')
        ->expectsOutputToContain('Checked 1 releases: 1 corrected, 0 raw values reconstructed, 1 kept literal by the safety net')
        ->assertSuccessful();
});
