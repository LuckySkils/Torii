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

test('an offset of 0 leaves every value unchanged', function () {
    config(['subtracker.feed.pubdate_offset_minutes' => 0]);
    $show = fixableShow('Test Show');
    $old = oldStyleRelease($show, 'OLD', '2026-09-21 06:33:36', '2026-09-21 14:00:00');

    $this->artisan('releases:fix-published')->assertSuccessful();

    expect($old->refresh()->published_at->toIso8601String())->toBe('2026-09-21T06:33:36+00:00');
});
