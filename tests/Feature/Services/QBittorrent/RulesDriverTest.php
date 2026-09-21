<?php

declare(strict_types=1);

use App\Models\Release;
use App\Models\Show;
use App\Services\QBittorrent\RulesDriver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.feed_path' => 'SubsPlease 1080p',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
    ]);
});

function makeShow(string $name, bool $tracked = false): Show
{
    return Show::create([
        'name' => $name,
        'slug' => str($name)->slug(),
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => $tracked,
    ]);
}

test('track creates a fresh rule with empty history when none existed', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    app(RulesDriver::class)->track(makeShow('Grand Blue S3'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'setRule')) {
            return false;
        }

        $ruleDef = json_decode($request['ruleDef'], true);

        return $request['ruleName'] === '[ST] Grand Blue S3'
            && $ruleDef['enabled'] === true
            && $ruleDef['mustContain'] === '^\[SubsPlease\] Grand Blue S3 - \d'
            && $ruleDef['assignedCategory'] === 'Anime'
            && $ruleDef['previouslyMatchedEpisodes'] === []
            && $ruleDef['lastMatch'] === '';
    });
});

test('track preserves previouslyMatchedEpisodes and lastMatch but overwrites stale managed fields', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] Grand Blue S3' => [
                'mustContain' => 'some stale pattern',
                'enabled' => false,
                'previouslyMatchedEpisodes' => ['01', '02'],
                'lastMatch' => '2026-01-01T00:00:00Z',
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    app(RulesDriver::class)->track(makeShow('Grand Blue S3'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'setRule')) {
            return false;
        }

        $ruleDef = json_decode($request['ruleDef'], true);

        return $ruleDef['mustContain'] === '^\[SubsPlease\] Grand Blue S3 - \d'
            && $ruleDef['enabled'] === true
            && $ruleDef['previouslyMatchedEpisodes'] === ['01', '02']
            && $ruleDef['lastMatch'] === '2026-01-01T00:00:00Z';
    });
});

test('untrack disables an existing rule while leaving every other field untouched', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] Grand Blue S3' => [
                'mustContain' => '^\[SubsPlease\] Grand Blue S3 - \d',
                'enabled' => true,
                'assignedCategory' => 'Anime',
                'previouslyMatchedEpisodes' => ['01'],
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    app(RulesDriver::class)->untrack(makeShow('Grand Blue S3'));

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'setRule')) {
            return false;
        }

        $ruleDef = json_decode($request['ruleDef'], true);

        return $request['ruleName'] === '[ST] Grand Blue S3'
            && $ruleDef['enabled'] === false
            && $ruleDef['mustContain'] === '^\[SubsPlease\] Grand Blue S3 - \d'
            && $ruleDef['assignedCategory'] === 'Anime'
            && $ruleDef['previouslyMatchedEpisodes'] === ['01'];
    });
});

test('untrack does nothing when no rule exists for the show', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([]), 200),
    ]);

    app(RulesDriver::class)->untrack(makeShow('Grand Blue S3'));

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setRule'));
});

test('onNewRelease refreshes the feed item only for a tracked show, and only once within the debounce window', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/rss/refreshItem' => Http::response('', 200),
    ]);

    $trackedShow = makeShow('Grand Blue S3', tracked: true);
    $untrackedShow = makeShow('One Piece', tracked: false);

    $trackedRelease = Release::create([
        'show_id' => $trackedShow->id,
        'guid' => 'GUID-1',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $untrackedRelease = Release::create([
        'show_id' => $untrackedShow->id,
        'guid' => 'GUID-2',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:BBBB',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $orphanRelease = Release::create([
        'show_id' => null,
        'guid' => 'GUID-3',
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:CCCC',
        'published_at' => now(),
        'first_seen_at' => now(),
    ]);

    $driver = app(RulesDriver::class);

    $driver->onNewRelease($untrackedRelease);
    $driver->onNewRelease($orphanRelease);
    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'refreshItem'));

    $driver->onNewRelease($trackedRelease);
    Http::assertSentCount(2); // login + refreshItem

    $driver->onNewRelease($trackedRelease);
    Http::assertSentCount(2); // debounced, no second refreshItem
});

test('reconcile creates missing rules, updates wrong ones, disables untracked-but-enabled rules, and leaves orphans alone', function () {
    makeShow('Needs Creation', tracked: true);
    makeShow('Needs Update', tracked: true);
    makeShow('Already Correct', tracked: true);
    makeShow('No Longer Tracked', tracked: false);
    makeShow('Already Disabled', tracked: false);

    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([
            '[ST] Needs Update' => [
                'enabled' => true,
                'mustContain' => 'wrong pattern',
                'mustNotContain' => '',
                'useRegex' => true,
                'episodeFilter' => '',
                'smartFilter' => true,
                'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
                'ignoreDays' => 0,
                'assignedCategory' => 'Anime',
            ],
            '[ST] Already Correct' => [
                'enabled' => true,
                'mustContain' => '^\[SubsPlease\] Already Correct - \d',
                'mustNotContain' => '',
                'useRegex' => true,
                'episodeFilter' => '',
                'smartFilter' => true,
                'affectedFeeds' => ['https://subsplease.org/rss/?r=1080'],
                'ignoreDays' => 0,
                'assignedCategory' => 'Anime',
            ],
            '[ST] No Longer Tracked' => [
                'enabled' => true,
                'mustContain' => '^\[SubsPlease\] No Longer Tracked - \d',
                'assignedCategory' => 'Anime',
            ],
            '[ST] Already Disabled' => [
                'enabled' => false,
                'mustContain' => '^\[SubsPlease\] Already Disabled - \d',
                'assignedCategory' => 'Anime',
            ],
            '[ST] Some Deleted Show' => [
                'enabled' => true,
                'mustContain' => '^\[SubsPlease\] Some Deleted Show - \d',
                'assignedCategory' => 'Anime',
            ],
        ]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    $report = app(RulesDriver::class)->reconcile();

    expect($report->created)->toBe(['[ST] Needs Creation'])
        ->and($report->updated)->toBe(['[ST] Needs Update'])
        ->and($report->disabled)->toBe(['[ST] No Longer Tracked'])
        ->and($report->orphaned)->toBe(['[ST] Some Deleted Show']);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setRule')
        && ($r['ruleName'] ?? null) === '[ST] Already Correct');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'setRule')
        && ($r['ruleName'] ?? null) === '[ST] Already Disabled');
});
