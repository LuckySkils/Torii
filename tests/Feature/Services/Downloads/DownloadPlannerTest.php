<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\TrackingMode;
use App\Models\Release;
use App\Models\Show;
use App\Services\Downloads\DownloadPlanner;

function planningShow(string $name = 'Grand Blue S3'): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function planningRelease(Show $show, array $overrides = []): Release
{
    static $sequence = 0;
    $sequence++;

    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-'.$sequence,
        'title' => 'irrelevant',
        'episode' => null,
        'version' => null,
        'is_batch' => false,
        'batch_from' => null,
        'batch_to' => null,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.str_pad((string) $sequence, 40, '0', STR_PAD_LEFT),
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

test('with no batch, plans every single episode, highest version wins, and creates a rule', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '01', 'published_at' => '2026-09-01 00:00:00']);
    planningRelease($show, ['episode' => '02', 'version' => 1, 'published_at' => '2026-09-08 00:00:00']);
    planningRelease($show, ['episode' => '02', 'version' => 2, 'published_at' => '2026-09-08 01:00:00']);

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->createRule)->toBeTrue()
        ->and($plan->trackingMode)->toBe(TrackingMode::Rule)
        ->and($plan->releaseIds)->toHaveCount(2);

    $queued = Release::whereIn('id', $plan->releaseIds)->get();
    expect($queued->firstWhere('episode', '02')->version)->toBe(2);
});

test('a batch covering everything known queues only the batch and creates no rule', function () {
    $show = planningShow();

    $batch = planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12]);
    planningRelease($show, ['episode' => '05']); // covered by the batch, should not be queued separately

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->createRule)->toBeFalse()
        ->and($plan->trackingMode)->toBe(TrackingMode::Batch)
        ->and($plan->releaseIds)->toBe([$batch->id]);
});

test('a batch plus a later episode queues both and still creates a rule', function () {
    $show = planningShow();

    $batch = planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12]);
    $ep13 = planningRelease($show, ['episode' => '13']);
    planningRelease($show, ['episode' => '07']); // covered by the batch, excluded

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->createRule)->toBeTrue()
        ->and($plan->trackingMode)->toBe(TrackingMode::Rule)
        ->and($plan->releaseIds)->toEqualCanonicalizing([$batch->id, $ep13->id]);
});

test('two batches with the same range are deduped to the newest', function () {
    $show = planningShow();

    planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12, 'published_at' => '2026-09-01 00:00:00']);
    $newer = planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12, 'published_at' => '2026-09-02 00:00:00']);

    $set = (new DownloadPlanner)->downloadableSet($show);

    expect($set)->toHaveCount(1)
        ->and($set->first()->id)->toBe($newer->id);
});

test('an unparseable batch range is treated as covering everything known, queuing no singles', function () {
    $show = planningShow();

    $batch = planningRelease($show, ['is_batch' => true, 'batch_from' => null, 'batch_to' => null]);
    planningRelease($show, ['episode' => '99']);

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->createRule)->toBeFalse()
        ->and($plan->trackingMode)->toBe(TrackingMode::Batch)
        ->and($plan->releaseIds)->toBe([$batch->id]);
});

test('re-tracking skips releases already sent or confirmed to exist', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '01', 'dispatch_status' => DispatchStatus::Sent]);
    planningRelease($show, ['episode' => '02', 'dispatch_status' => DispatchStatus::Exists]);
    $pending = planningRelease($show, ['episode' => '03']);

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->releaseIds)->toBe([$pending->id]);
});

test('specials (non-numbered, non-batch releases) are each included individually', function () {
    $show = planningShow();

    $batch = planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12]);
    $special1 = planningRelease($show, ['episode' => null, 'title' => 'special 1']);
    $special2 = planningRelease($show, ['episode' => null, 'title' => 'special 2']);

    $plan = (new DownloadPlanner)->plan($show);

    expect($plan->releaseIds)->toEqualCanonicalizing([$batch->id, $special1->id, $special2->id])
        ->and($plan->createRule)->toBeTrue();
});

// ── Several groups on one show (§6.4, Nyaa import of other groups) ───────────

test('an episode another group already has (sent, exists or downloaded) is not downloadable again', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '06', 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'dispatch_status' => DispatchStatus::Sent]);
    // Downloaded without a dispatch status (as the RSS rule's downloads are): still counts for other groups.
    $judas7 = planningRelease($show, ['episode' => '7', 'release_group' => 'Judas', 'source' => 'nyaa', 'downloaded_at' => now()]);
    planningRelease($show, ['episode' => '08', 'release_group' => 'Judas', 'source' => 'nyaa', 'dispatch_status' => DispatchStatus::Exists]);
    $subsPlease6 = planningRelease($show, ['episode' => '6', 'release_group' => 'SubsPlease']);
    planningRelease($show, ['episode' => '07', 'release_group' => 'SubsPlease']);
    planningRelease($show, ['episode' => '08', 'release_group' => 'SubsPlease']);
    $subsPlease9 = planningRelease($show, ['episode' => '09', 'release_group' => 'SubsPlease']);

    $downloadable = (new DownloadPlanner)->downloadableSet($show);

    // Judas' own episode 7 stays as it always did (same group); SubsPlease's 06, 07 and 08 don't.
    expect($downloadable->pluck('id')->sort()->values()->all())->toBe([$judas7->id, $subsPlease9->id])
        ->and($downloadable->pluck('id'))->not->toContain($subsPlease6->id);
});

test('the same group is unchanged: a higher version of an episode it already sent still counts as new', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '06', 'version' => null, 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'downloaded_at' => now()]);
    $v2 = planningRelease($show, ['episode' => '06', 'version' => 2, 'release_group' => 'Erai-raws', 'source' => 'nyaa']);

    expect((new DownloadPlanner)->downloadableSet($show)->pluck('id')->all())->toBe([$v2->id]);
});

test('a feed release without a stored group counts as SubsPlease', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '03', 'dispatch_status' => DispatchStatus::Sent]);
    planningRelease($show, ['episode' => '03', 'release_group' => 'Erai-raws', 'source' => 'nyaa']);

    expect((new DownloadPlanner)->downloadableSet($show))->toBeEmpty();
});

test('competing undispatched singles of one episode: SubsPlease first, then highest version, then newest', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '05', 'version' => 2, 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'published_at' => '2026-09-08 02:00:00']);
    $subsPlease = planningRelease($show, ['episode' => '5', 'release_group' => 'SubsPlease', 'published_at' => '2026-09-08 00:00:00']);
    planningRelease($show, ['episode' => '06', 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'published_at' => '2026-09-15 00:00:00']);
    $judasV2 = planningRelease($show, ['episode' => '06', 'version' => 2, 'release_group' => 'Judas', 'source' => 'nyaa', 'published_at' => '2026-09-14 00:00:00']);
    planningRelease($show, ['episode' => '07', 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'published_at' => '2026-09-22 00:00:00']);
    $newest7 = planningRelease($show, ['episode' => '07', 'release_group' => 'Judas', 'source' => 'nyaa', 'published_at' => '2026-09-22 05:00:00']);

    $ids = (new DownloadPlanner)->downloadableSet($show)->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$subsPlease->id, $judasV2->id, $newest7->id])->sort()->values()->all());
});

test('batches are unchanged by other groups\' episodes', function () {
    $show = planningShow();

    planningRelease($show, ['episode' => '06', 'release_group' => 'Erai-raws', 'source' => 'nyaa', 'dispatch_status' => DispatchStatus::Sent]);
    $batch = planningRelease($show, ['is_batch' => true, 'batch_from' => 1, 'batch_to' => 12, 'release_group' => 'SubsPlease']);

    expect((new DownloadPlanner)->downloadableSet($show)->pluck('id')->all())->toBe([$batch->id]);
});
