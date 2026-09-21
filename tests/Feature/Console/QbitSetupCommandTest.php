<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.feed_path' => 'SubsPlease 1080p',
        'subtracker.qbittorrent.category' => 'anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/app/version' => Http::response('v5.0.1', 200),
        'http://qbit.test:8080/api/v2/app/webapiVersion' => Http::response('2.9.3', 200),
    ]);
});

test('reports everything already configured and touches nothing', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
            'rss_refresh_interval' => 30,
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080'],
        ]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode([
            'anime' => ['name' => 'anime'],
        ]), 200),
    ]);

    $this->artisan('qbit:setup')->assertExitCode(0);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'addFeed'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'createCategory'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setPreferences'));
});

test('fix-prefs enables preferences, adds the missing feed, and creates the missing category', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => false,
            'rss_auto_downloading_enabled' => false,
            'rss_refresh_interval' => 30,
        ]), 200),
        'http://qbit.test:8080/api/v2/app/setPreferences' => Http::response('', 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/addFeed' => Http::response('', 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/torrents/createCategory' => Http::response('', 200),
    ]);

    $this->artisan('qbit:setup', ['--fix-prefs' => true])->assertExitCode(0);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'setPreferences')
        && json_decode($r['json'], true) === ['rss_processing_enabled' => true, 'rss_auto_downloading_enabled' => true]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'addFeed')
        && $r['url'] === 'https://subsplease.org/rss/?r=1080'
        && $r['path'] === 'SubsPlease 1080p');

    Http::assertSent(fn ($r) => str_contains($r->url(), 'createCategory') && $r['category'] === 'anime');
});

test('self-test passes when the category is reflected at the top level', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080'],
        ]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['anime' => []]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] __selftest' => [
                'mustContain' => 'selftest',
                'useRegex' => false,
                'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
                'assignedCategory' => 'anime',
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/matchingArticles*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/removeRule' => Http::response('', 200),
    ]);

    $this->artisan('qbit:setup', ['--self-test' => true])->assertExitCode(0);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'removeRule') && $r['ruleName'] === '[ST] __selftest');
});

test('self-test passes when the category is only reflected under the nested torrentParams object', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080'],
        ]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['anime' => []]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] __selftest' => [
                'mustContain' => 'selftest',
                'useRegex' => false,
                'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
                'torrentParams' => ['category' => 'anime'],
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/matchingArticles*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/removeRule' => Http::response('', 200),
    ]);

    $this->artisan('qbit:setup', ['--self-test' => true])->assertExitCode(0);
});

test('self-test fails and stops without guessing a fix when the category never took effect', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode([
            'SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080'],
        ]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['anime' => []]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] __selftest' => [
                'mustContain' => 'selftest',
                'useRegex' => false,
                'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/matchingArticles*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/removeRule' => Http::response('', 200),
    ]);

    $this->artisan('qbit:setup', ['--self-test' => true])->assertExitCode(1);

    Http::assertSentCount(10);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'removeRule'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setPreferences'));
});
