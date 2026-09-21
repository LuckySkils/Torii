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
