<?php

declare(strict_types=1);

use App\Models\FeedPoll;
use App\Models\Release;
use App\Models\Show;
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
