<?php

declare(strict_types=1);

use App\Models\FeedPoll;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.feed_path' => 'SubsPlease 1080p',
        'subtracker.qbittorrent.category' => 'Anime',
    ]);
});

test('renders the dashboard with qbit health and the latest releases when qbit is reachable', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/app/version' => Http::response('v5.0.3', 200),
        'http://qbit.test:8080/api/v2/app/webapiVersion' => Http::response('2.11.2', 200),
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080'],
        ]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['Anime' => []]), 200),
    ]);

    FeedPoll::create([
        'started_at' => now(),
        'finished_at' => now(),
        'http_status' => 200,
        'not_modified' => false,
        'items_total' => 5,
        'items_new' => 2,
        'shows_new' => 1,
    ]);

    $show = Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Release::create([
        'show_id' => $show->id,
        'guid' => 'GUID-1',
        'title' => '[SubsPlease] Grand Blue S3 - 12v2 (1080p) [ABCD1234].mkv',
        'episode' => '12',
        'version' => 2,
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:ABCD1234',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $page->component('Dashboard')
            ->where('driver', 'rules')
            ->where('flash.success', null)
            ->where('flash.error', null)
            ->where('health.qbit.reachable', true)
            ->where('health.qbit.version', 'v5.0.3')
            ->where('health.qbit.auth', true)
            ->where('health.qbit.feed', true)
            ->where('health.qbit.prefs', true)
            ->where('health.qbit.category', true)
            ->where('health.driver', 'rules')
            ->where('health.pollMode', 'base')
            ->where('health.lastPoll.itemsNew', 2)
            ->has('latestReleases', 1)
            ->where('latestReleases.0.show.name', 'Grand Blue S3')
            ->where('latestReleases.0.version', 2)
            ->where('latestReleases.0.isBatch', false);
    });
});

function dashboardShow(string $name, array $overrides = []): Show
{
    return Show::create(array_merge([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now()->subDays(30),
        'last_seen_at' => now(),
    ], $overrides));
}

function dashboardRelease(?Show $show, string $guid, array $overrides = []): Release
{
    return Release::create(array_merge([
        'show_id' => $show?->id,
        'guid' => $guid,
        'title' => $guid,
        'episode' => '05',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$guid,
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

function qbitUnreachable(): void
{
    Http::preventStrayRequests();
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });
}

test('latest releases are ordered by published_at desc, then first_seen_at desc', function () {
    qbitUnreachable();
    $this->travelTo('2026-09-24 12:00:00');
    $show = dashboardShow('Show');

    dashboardRelease($show, 'OLD-BATCH', ['is_batch' => true, 'episode' => null, 'published_at' => now()->subDay(), 'first_seen_at' => now()]);
    dashboardRelease($show, 'RECENT', ['published_at' => now()->subHours(3), 'first_seen_at' => now()->subHours(3)]);
    dashboardRelease($show, 'TIE-EARLY-SEEN', ['published_at' => now()->subHours(5), 'first_seen_at' => now()->subHours(5)]);
    dashboardRelease($show, 'TIE-LATE-SEEN', ['published_at' => now()->subHours(5), 'first_seen_at' => now()->subHours(4)]);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('latestReleases.0.title', 'RECENT')
        ->where('latestReleases.1.title', 'TIE-LATE-SEEN')
        ->where('latestReleases.2.title', 'TIE-EARLY-SEEN')
        ->where('latestReleases.3.title', 'OLD-BATCH'));
});

test('latest releases carry poster, batch, first-episode and new-show fields', function () {
    qbitUnreachable();
    $this->travelTo('2026-09-24 12:00:00');

    $newShow = dashboardShow('New Show', ['first_seen_at' => now()->subDays(2), 'image_status' => 'found']);
    ShowImage::create([
        'show_id' => $newShow->id,
        'source_url' => 'https://subsplease.org/poster.jpg',
        'mime' => 'image/jpeg',
        'data' => base64_encode('bytes'),
        'size' => 5,
        'sha256' => hash('sha256', 'bytes'),
        'fetched_at' => now(),
    ]);
    dashboardRelease($newShow, 'EP01', ['episode' => '01', 'published_at' => now()->subHours(10)]);
    dashboardRelease($newShow, 'EP02', ['episode' => '02', 'published_at' => now()->subHours(9)]);

    $oldShow = dashboardShow('Old Show');
    dashboardRelease($oldShow, 'EARLIEST', ['episode' => '07', 'published_at' => now()->subHours(8)]);
    dashboardRelease($oldShow, 'BATCH', ['episode' => null, 'is_batch' => true, 'batch_from' => 1, 'batch_to' => 12, 'published_at' => now()->subHours(7)]);

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('latestReleases.0.title', 'BATCH')
        ->where('latestReleases.0.isBatch', true)
        ->where('latestReleases.0.batchFrom', 1)
        ->where('latestReleases.0.batchTo', 12)
        ->where('latestReleases.0.isFirstEpisode', false)
        ->where('latestReleases.0.isNewShow', false)
        ->where('latestReleases.0.show.imageUrl', null)
        ->where('latestReleases.0.show.imageStatus', 'none')
        ->where('latestReleases.1.title', 'EARLIEST')
        ->where('latestReleases.1.isFirstEpisode', true)
        ->where('latestReleases.2.title', 'EP02')
        ->where('latestReleases.2.isFirstEpisode', false)
        ->where('latestReleases.2.isNewShow', true)
        ->where('latestReleases.2.show.imageStatus', 'found')
        ->where('latestReleases.2.show.imageUrl', "/shows/{$newShow->id}/image?v=".substr(hash('sha256', 'bytes'), 0, 8))
        ->where('latestReleases.3.title', 'EP01')
        ->where('latestReleases.3.isFirstEpisode', true)
        ->missing('latestReleases.2.show.data'));
});

test('latest releases load shows and posters without lazy loading (no N+1)', function () {
    qbitUnreachable();

    foreach (range(1, 5) as $i) {
        $show = dashboardShow("Show {$i}");
        ShowImage::create([
            'show_id' => $show->id,
            'source_url' => 'https://subsplease.org/poster.jpg',
            'mime' => 'image/jpeg',
            'data' => base64_encode("bytes {$i}"),
            'size' => 7,
            'sha256' => hash('sha256', "bytes {$i}"),
            'fetched_at' => now(),
        ]);
        dashboardRelease($show, "N1-{$i}-A");
        dashboardRelease($show, "N1-{$i}-B");
    }
    dashboardRelease(null, 'NO-SHOW');

    Model::preventLazyLoading();

    try {
        $this->get('/')->assertOk()->assertInertia(fn ($page) => $page->has('latestReleases', 11));
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('health exposes the median polling delay, excluding the first poll', function () {
    qbitUnreachable();
    $this->travelTo('2026-09-24 12:00:00');

    FeedPoll::create([
        'started_at' => now()->subDays(2),
        'finished_at' => now()->subDays(2),
        'http_status' => 200,
        'not_modified' => false,
        'items_total' => 1,
        'items_new' => 1,
        'shows_new' => 1,
    ]);
    $show = dashboardShow('Show');

    // First-poll backlog: a huge delay that must be ignored.
    dashboardRelease($show, 'BACKLOG', ['published_at' => now()->subDays(5), 'first_seen_at' => now()->subDays(2)]);

    foreach (['A' => 5, 'B' => 10, 'C' => 30] as $guid => $minutes) {
        dashboardRelease($show, $guid, ['published_at' => now()->subHour(), 'first_seen_at' => now()->subHour()->addMinutes($minutes)]);
    }

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('health.delay.medianSeconds', 600)
        ->where('health.delay.sampleSize', 3));
});

test('health delay is null before any successful poll', function () {
    qbitUnreachable();

    $this->get('/')->assertInertia(fn ($page) => $page
        ->where('health.delay.medianSeconds', null)
        ->where('health.delay.sampleSize', 0));
});

test('reports qbit as unreachable without erroring when the connection fails', function () {
    Http::preventStrayRequests();
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $response = $this->get('/');

    $response->assertOk();
    $response->assertInertia(function ($page) {
        $page->component('Dashboard')
            ->where('health.qbit.reachable', false)
            ->where('health.qbit.auth', false)
            ->where('health.lastPoll', null);
    });
});
