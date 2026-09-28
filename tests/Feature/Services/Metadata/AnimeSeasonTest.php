<?php

declare(strict_types=1);

use App\Enums\AnimeSeason;
use App\Jobs\SyncAnimeSeasons;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;

test('the season follows the Tokyo calendar quarter', function (string $utc, AnimeSeason $season, int $year) {
    expect(AnimeSeason::forDate(Carbon::parse($utc, 'UTC')))->toBe([$season, $year]);
})->with([
    'mid winter' => ['2026-02-10 00:00:00', AnimeSeason::Winter, 2026],
    'spring starts' => ['2026-04-01 00:00:00', AnimeSeason::Spring, 2026],
    'late june' => ['2026-06-30 12:00:00', AnimeSeason::Spring, 2026],
    'summer' => ['2026-09-28 12:00:00', AnimeSeason::Summer, 2026],
    // 15:00 UTC on 30 Sep is already 1 Oct in Tokyo.
    'fall by Tokyo time' => ['2026-09-30 15:00:00', AnimeSeason::Fall, 2026],
    'still summer in Tokyo' => ['2026-09-30 14:59:59', AnimeSeason::Summer, 2026],
    'new year in Tokyo' => ['2026-12-31 15:30:00', AnimeSeason::Winter, 2027],
]);

test('the next season rolls FALL over to WINTER of the next year', function () {
    expect(AnimeSeason::Winter->next(2026))->toBe([AnimeSeason::Spring, 2026])
        ->and(AnimeSeason::Spring->next(2026))->toBe([AnimeSeason::Summer, 2026])
        ->and(AnimeSeason::Summer->next(2026))->toBe([AnimeSeason::Fall, 2026])
        ->and(AnimeSeason::Fall->next(2026))->toBe([AnimeSeason::Winter, 2027]);
});

test('anime:sync-season with no arguments queues the current season', function () {
    Queue::fake();
    Carbon::setTestNow('2026-09-28 12:00:00');

    $this->artisan('anime:sync-season')->assertSuccessful();

    Queue::assertPushed(SyncAnimeSeasons::class, fn (SyncAnimeSeasons $job) => $job->seasons === [['season' => 'SUMMER', 'year' => 2026]]
        && $job->allLinked === false);
});

test('anime:sync-season --next in the fall queues winter of the next year', function () {
    Queue::fake();
    Carbon::setTestNow('2026-11-15 12:00:00');

    $this->artisan('anime:sync-season --next --all-linked')->assertSuccessful();

    Queue::assertPushed(SyncAnimeSeasons::class, fn (SyncAnimeSeasons $job) => $job->seasons === [['season' => 'WINTER', 'year' => 2027]]
        && $job->allLinked === true);
});

test('anime:sync-season takes an explicit season and year, case-insensitively', function () {
    Queue::fake();

    $this->artisan('anime:sync-season spring 2024')->assertSuccessful();

    Queue::assertPushed(SyncAnimeSeasons::class, fn (SyncAnimeSeasons $job) => $job->seasons === [['season' => 'SPRING', 'year' => 2024]]);
});

test('anime:sync-season rejects an unknown season', function () {
    Queue::fake();

    $this->artisan('anime:sync-season autumn 2026')->assertExitCode(2);

    Queue::assertNothingPushed();
});

test('the previous season rolls WINTER back to FALL of the year before', function () {
    expect(AnimeSeason::Winter->previous(2027))->toBe([AnimeSeason::Fall, 2026])
        ->and(AnimeSeason::Spring->previous(2027))->toBe([AnimeSeason::Winter, 2027])
        ->and(AnimeSeason::Summer->previous(2027))->toBe([AnimeSeason::Spring, 2027])
        ->and(AnimeSeason::Fall->previous(2027))->toBe([AnimeSeason::Summer, 2027]);
});

test('the weekly job covers the previous, current and next season plus linked anime', function () {
    Carbon::setTestNow('2026-12-01 00:00:00');

    $job = SyncAnimeSeasons::weekly();

    expect($job->seasons)->toBe([
        ['season' => 'SUMMER', 'year' => 2026],
        ['season' => 'FALL', 'year' => 2026],
        ['season' => 'WINTER', 'year' => 2027],
    ])->and($job->allLinked)->toBeTrue();
});

test('in winter the weekly job reaches back to the previous year', function () {
    Carbon::setTestNow('2027-02-01 00:00:00');

    expect(SyncAnimeSeasons::weekly()->seasons)->toBe([
        ['season' => 'FALL', 'year' => 2026],
        ['season' => 'WINTER', 'year' => 2027],
        ['season' => 'SPRING', 'year' => 2027],
    ]);
});
