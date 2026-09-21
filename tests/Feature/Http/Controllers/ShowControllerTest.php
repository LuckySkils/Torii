<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Enums\ImageStatus;
use App\Enums\PremiereSource;
use App\Enums\Season;
use App\Enums\TrackingMode;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowImage;

function makeIndexableShow(string $name, bool $tracked, string $lastSeen): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => $lastSeen,
        'is_tracked' => $tracked,
    ]);
}

test('index lists shows with their latest release and respects filters and sort', function () {
    $tracked = makeIndexableShow('Grand Blue S3', true, '2026-09-20 00:00:00');
    $untracked = makeIndexableShow('One Piece', false, '2026-09-21 00:00:00');

    Release::create([
        'show_id' => $tracked->id,
        'guid' => 'GUID-1',
        'title' => '[SubsPlease] Grand Blue S3 - 11 (1080p) [AAAA1111].mkv',
        'episode' => '11',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA1111',
        'published_at' => '2026-09-19 00:00:00',
        'first_seen_at' => '2026-09-19 00:05:00',
    ]);
    Release::create([
        'show_id' => $tracked->id,
        'guid' => 'GUID-2',
        'title' => '[SubsPlease] Grand Blue S3 - 12v2 (1080p) [BBBB2222].mkv',
        'episode' => '12',
        'version' => 2,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB2222',
        'published_at' => '2026-09-20 00:00:00',
        'first_seen_at' => '2026-09-20 00:05:00',
    ]);

    $response = $this->get('/shows?tracked=yes');

    $response->assertOk();
    $response->assertInertia(function ($page) use ($tracked) {
        $page->component('Shows/Index')
            ->has('shows.data', 1)
            ->where('shows.data.0.id', $tracked->id)
            ->where('shows.data.0.firstSeenAt', $tracked->first_seen_at->toIso8601String())
            ->where('shows.data.0.latest.episode', '12')
            ->where('shows.data.0.latest.isBatch', false)
            ->where('filters.tracked', 'yes');
    });

    $this->get('/shows?q=One')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'One Piece'));

    $this->get('/shows?sort=last_seen')->assertInertia(fn ($page) => $page->where('shows.data.0.name', 'One Piece'));

    expect($untracked)->not->toBeNull();
});

test('search is case-insensitive and treats %, _ and \\ as literal characters', function () {
    makeIndexableShow('LIAR GAME', false, '2026-09-20 00:00:00');
    makeIndexableShow('100% OJ', false, '2026-09-20 00:00:00');
    makeIndexableShow('One Piece', false, '2026-09-20 00:00:00');

    $this->get('/shows?q=liar')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'LIAR GAME'));

    $this->get('/shows?q=Liar')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'LIAR GAME'));

    $this->get('/shows?q=100%25')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', '100% OJ'));

    // A bare "%" must not act as a wildcard matching every show — only the one that actually contains it.
    $this->get('/shows?q=%25')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', '100% OJ'));

    $this->get('/shows?q=nonexistent')->assertInertia(fn ($page) => $page->has('shows.data', 0));
});

test('index exposes tracking mode, batch presence, and queued/downloadable counts', function () {
    $show = makeIndexableShow('Grand Blue S3', true, '2026-09-20 00:00:00');
    $show->update(['tracking_mode' => TrackingMode::Batch]);

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-BATCH',
        'title' => 'batch',
        'is_batch' => true,
        'batch_from' => 1,
        'batch_to' => 12,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
        'dispatch_status' => DispatchStatus::Sent,
    ]);
    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-13',
        'title' => 'ep13',
        'episode' => '13',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $this->get('/shows')->assertInertia(function ($page) {
        $page->where('shows.data.0.trackingMode', 'batch')
            ->where('shows.data.0.hasBatch', true)
            ->where('shows.data.0.queuedCount', 1)
            ->where('shows.data.0.downloadableCount', 1);
    });
});

test('index exposes image status and a versioned image URL once an image is stored', function () {
    $withImage = makeIndexableShow('Grand Blue S3', false, '2026-09-20 00:00:00');
    $withImage->update(['image_status' => ImageStatus::Found]);
    $bytes = 'fake-bytes';
    ShowImage::create([
        'show_id' => $withImage->id,
        'source_url' => 'https://subsplease.org/poster.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode($bytes),
        'size' => strlen($bytes),
        'width' => 10,
        'height' => 10,
        'sha256' => hash('sha256', $bytes),
        'fetched_at' => now(),
    ]);

    $withoutImage = makeIndexableShow('One Piece', false, '2026-09-19 00:00:00');

    $this->get('/shows?sort=last_seen')->assertInertia(function ($page) use ($withImage) {
        $page->where('shows.data.0.imageStatus', 'found')
            ->where('shows.data.0.imageUrl', "/shows/{$withImage->id}/image?v=".substr(hash('sha256', 'fake-bytes'), 0, 8))
            ->where('shows.data.0.imageWidth', 10)
            ->where('shows.data.0.imageHeight', 10);
    });

    $this->get('/shows?q=One')->assertInertia(fn ($page) => $page->where('shows.data.0.imageStatus', 'none')
        ->where('shows.data.0.imageUrl', null)
        ->where('shows.data.0.imageWidth', null)
        ->where('shows.data.0.imageHeight', null));

    expect($withoutImage)->not->toBeNull();
});

test('index filters by season and year, sorts by premiered date newest-first with nulls last, and lists distinct years', function () {
    $spring2026 = makeIndexableShow('Spring Show', false, '2026-09-20 00:00:00');
    $spring2026->update([
        'premiered_at' => '2026-04-01 00:00:00',
        'premiere_source' => PremiereSource::Episode1,
        'season' => Season::Spring,
        'season_year' => 2026,
    ]);

    $winter2025 = makeIndexableShow('Winter Show', false, '2026-09-20 00:00:00');
    $winter2025->update([
        'premiered_at' => '2025-02-01 00:00:00',
        'premiere_source' => PremiereSource::Episode1,
        'season' => Season::Winter,
        'season_year' => 2025,
    ]);

    $noPremiere = makeIndexableShow('No Premiere Show', false, '2026-09-20 00:00:00');

    $this->get('/shows?season=spring')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'Spring Show')
        ->where('shows.data.0.season', 'spring')
        ->where('shows.data.0.seasonYear', 2026)
        ->where('shows.data.0.premiereSource', 'episode1')
        ->where('shows.data.0.premieredAt', $spring2026->premiered_at->toIso8601String()));

    $this->get('/shows?year=2025')->assertInertia(fn ($page) => $page->has('shows.data', 1)
        ->where('shows.data.0.name', 'Winter Show'));

    $this->get('/shows?sort=premiered')->assertInertia(fn ($page) => $page->where('shows.data.0.name', 'Spring Show')
        ->where('shows.data.1.name', 'Winter Show')
        ->where('shows.data.2.name', 'No Premiere Show'));

    $this->get('/shows')->assertInertia(fn ($page) => $page->where('filterOptions.years', [2026, 2025]));

    expect($noPremiere)->not->toBeNull();
});

test('show renders the show and all of its releases newest first', function () {
    $show = makeIndexableShow('Grand Blue S3', false, '2026-09-20 00:00:00');

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-1',
        'title' => 'older batch',
        'is_batch' => true,
        'batch_from' => 1,
        'batch_to' => 12,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => '2026-09-19 00:00:00',
        'first_seen_at' => '2026-09-19 00:05:00',
        'dispatch_status' => DispatchStatus::Error,
        'dispatch_error' => 'boom',
    ]);
    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-2',
        'title' => 'newer v2',
        'version' => 2,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => '2026-09-20 00:00:00',
        'first_seen_at' => '2026-09-20 00:05:00',
    ]);

    $response = $this->get("/shows/{$show->id}");

    $response->assertOk();
    $response->assertInertia(function ($page) use ($show) {
        $page->component('Shows/Show')
            ->where('show.id', $show->id)
            ->where('show.name', 'Grand Blue S3')
            ->where('show.firstSeenAt', $show->first_seen_at->toIso8601String())
            ->has('releases', 2)
            ->where('releases.0.title', 'newer v2')
            ->where('releases.0.version', 2)
            ->where('releases.0.isBatch', false)
            ->where('releases.0.dispatchStatus', null)
            ->where('releases.1.title', 'older batch')
            ->where('releases.1.version', null)
            ->where('releases.1.isBatch', true)
            ->where('releases.1.batchFrom', 1)
            ->where('releases.1.batchTo', 12)
            ->where('releases.1.dispatchStatus', 'error')
            ->where('releases.1.dispatchError', 'boom')
            ->missing('stats');
    });
});
