<?php

declare(strict_types=1);

use App\Models\BootstrapTask;
use App\Models\FeedPoll;
use App\Models\NotificationLog;
use App\Services\Bootstrap\BootstrapTasks;
use App\Services\Reconciler\ListenerHeartbeat;
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
        'subtracker.notifications.enabled' => false,
    ]);

    // Bootstrap done, so only the checks under test can fail.
    foreach (app(BootstrapTasks::class)->all() as $task) {
        BootstrapTask::create(['key' => $task->key, 'started_at' => now(), 'completed_at' => now()]);
    }
});

/**
 * @param  array<string, mixed>  $overrides  URL pattern => response, replacing the healthy default
 */
function fakeQbit(array $overrides = []): void
{
    Http::preventStrayRequests();
    Http::fake(array_merge([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/app/version' => Http::response('v5.0.3', 200),
        'http://qbit.test:8080/api/v2/app/webapiVersion' => Http::response('2.11.2', 200),
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode(['rss_processing_enabled' => true, 'rss_auto_downloading_enabled' => true]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode(['SubsPlease 1080p' => ['url' => 'https://subsplease.org/rss/?r=1080']]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['Anime' => []]), 200),
    ], $overrides));
}

/**
 * @return array<string, array{key: string, ok: bool, label: string, detail: string|null}>
 */
function healthChecks(): array
{
    $checks = test()->get('/')->assertOk()->viewData('page')['props']['health']['checks'];

    return collect($checks)->keyBy('key')->all();
}

test('every check is listed, healthy ones included, in a fixed order', function () {
    fakeQbit();
    FeedPoll::create(['started_at' => now(), 'finished_at' => now(), 'http_status' => 200, 'not_modified' => false, 'items_total' => 1, 'items_new' => 0, 'shows_new' => 0]);

    $checks = healthChecks();

    expect(array_keys($checks))->toBe(['qbit.reachable', 'qbit.auth', 'qbit.feed', 'qbit.prefs', 'qbit.category', 'notifications', 'bootstrap', 'feed.polling'])
        ->and(collect($checks)->every(fn (array $check) => $check['ok'] === true))->toBeTrue()
        ->and($checks['qbit.reachable'])->toBe(['key' => 'qbit.reachable', 'ok' => true, 'label' => 'qBittorrent reachable', 'detail' => null, 'skipped' => false])
        ->and(collect($checks)->every(fn (array $check) => $check['skipped'] === false))->toBeTrue()
        ->and($checks['feed.polling']['detail'])->toBeNull()
        ->and($checks['bootstrap']['detail'])->toBeNull();
});

test('notifications switched off is not a problem: ok, with a detail saying so', function () {
    fakeQbit();

    expect(healthChecks()['notifications'])->toBe([
        'key' => 'notifications', 'ok' => true, 'label' => 'Notifications', 'detail' => 'Disabled: NTFY_URL is not set.', 'skipped' => false,
    ]);
});

test('notifications are a problem only when the last attempt failed, with its stored error', function () {
    fakeQbit();
    config(['subtracker.notifications.enabled' => true]);

    NotificationLog::create(['kind' => 'test', 'status' => 'sent', 'sent_at' => now()->subHour()]);
    expect(healthChecks()['notifications'])->toMatchArray(['ok' => true, 'detail' => null]);

    NotificationLog::create(['kind' => 'test', 'status' => 'error', 'error' => 'ntfy returned 401 Unauthorized', 'sent_at' => now()]);
    expect(healthChecks()['notifications'])->toMatchArray(['ok' => false, 'detail' => 'Last notification failed: ntfy returned 401 Unauthorized']);
});

test('qBittorrent unreachable: the exception message, and the dependent checks say they were not run', function () {
    Http::preventStrayRequests();
    Http::fake(['http://qbit.test:8080/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect to qbit.test port 8080')]);

    $checks = healthChecks();

    // The real problem isn't skipped; the four checks after it are, and nothing else is.
    expect($checks['qbit.reachable'])->toMatchArray(['ok' => false, 'skipped' => false, 'detail' => 'cURL error 7: Failed to connect to qbit.test port 8080'])
        ->and($checks['qbit.auth'])->toMatchArray(['ok' => false, 'skipped' => true, 'detail' => "Not checked: qBittorrent isn't reachable."])
        ->and($checks['qbit.category'])->toMatchArray(['ok' => false, 'skipped' => true, 'detail' => "Not checked: qBittorrent isn't reachable."])
        ->and(collect($checks)->where('skipped', true)->keys()->all())->toBe(['qbit.auth', 'qbit.feed', 'qbit.prefs', 'qbit.category']);
});

test('a failed login carries the client\'s error', function () {
    fakeQbit(['http://qbit.test:8080/api/v2/auth/login' => Http::response('Fails.', 200)]);

    $checks = healthChecks();

    expect($checks['qbit.reachable']['ok'])->toBeTrue()
        ->and($checks['qbit.auth']['ok'])->toBeFalse()
        ->and($checks['qbit.auth']['skipped'])->toBeFalse()
        ->and($checks['qbit.auth']['detail'])->toContain('Fails.')
        ->and($checks['qbit.feed']['detail'])->toBe("Not checked: couldn't log in to qBittorrent.")
        ->and(collect($checks)->where('skipped', true)->keys()->all())->toBe(['qbit.feed', 'qbit.prefs', 'qbit.category']);
});

test('feed, preferences and category failures name the setting or config value involved', function () {
    fakeQbit([
        'http://qbit.test:8080/api/v2/app/preferences' => Http::response(json_encode(['rss_processing_enabled' => true, 'rss_auto_downloading_enabled' => false]), 200),
        'http://qbit.test:8080/api/v2/rss/items*' => Http::response(json_encode(['Other' => ['url' => 'https://example.com/rss']]), 200),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response(json_encode(['Movies' => []]), 200),
    ]);

    $checks = healthChecks();

    expect($checks['qbit.auth']['ok'])->toBeTrue()
        // Independent failures are each real problems, none skipped.
        ->and(collect($checks)->where('skipped', true)->all())->toBe([])
        ->and($checks['qbit.prefs'])->toMatchArray(['ok' => false, 'detail' => 'qBittorrent preference off: rss_auto_downloading_enabled.'])
        ->and($checks['qbit.feed'])->toMatchArray(['ok' => false, 'detail' => "FEED_URL https://subsplease.org/rss/?r=1080 isn't among qBittorrent's RSS feeds."])
        ->and($checks['qbit.category'])->toMatchArray(['ok' => false, 'detail' => 'QBIT_CATEGORY "Anime" doesn\'t exist in qBittorrent.']);
});

test('a failed last poll is a problem with its stored error and status', function () {
    fakeQbit();
    FeedPoll::create(['started_at' => now(), 'finished_at' => now(), 'http_status' => null, 'not_modified' => false, 'items_total' => 0, 'items_new' => 0, 'shows_new' => 0, 'error' => 'Connection timed out']);

    expect(healthChecks()['feed.polling'])->toMatchArray(['ok' => false, 'detail' => 'Last poll failed: Connection timed out']);
});

test('no poll yet is not a problem', function () {
    fakeQbit();

    expect(healthChecks()['feed.polling'])->toMatchArray(['ok' => true, 'detail' => 'No poll has run yet.']);
});

test('bootstrap: a failed task is a problem with its error; pending tasks are only progress', function () {
    fakeQbit();
    BootstrapTask::where('key', 'anime.initial-sync')->update(['completed_at' => null, 'error' => 'AniList rate limited (status 429); retry in 60s.']);
    BootstrapTask::where('key', 'anime.initial-covers')->update(['completed_at' => null, 'started_at' => null]);

    expect(healthChecks()['bootstrap'])->toMatchArray([
        'ok' => false,
        'detail' => 'Sync anime seasons and match shows: AniList rate limited (status 429); retry in 60s.',
    ]);

    BootstrapTask::where('key', 'anime.initial-sync')->update(['completed_at' => now(), 'error' => null]);

    expect(healthChecks()['bootstrap'])->toMatchArray(['ok' => true, 'detail' => 'In progress: 6 of 7 tasks done.']);
});

test('the reconciler listener check appears only with the reconciler on: connected, disconnected, or gone', function () {
    fakeQbit();
    config(['subtracker.reconciler.enabled' => true]);
    $listener = fn () => collect(test()->get('/')->assertOk()->viewData('page')['props']['health']['checks'])->firstWhere('key', 'reconciler.listener');
    $heartbeat = app(ListenerHeartbeat::class);

    expect($listener())->toMatchArray(['ok' => false])
        ->and($listener()['detail'])->toContain('Shoko: no heartbeat');

    $heartbeat->beat('shoko', true);
    $heartbeat->beat('jellyfin', false);
    expect($listener()['detail'])->toStartWith('Reconciler listener disconnected. Jellyfin: disconnected since');

    $heartbeat->beat('jellyfin', true);
    expect($listener())->toMatchArray(['ok' => true, 'detail' => null]);

    $this->travel(3)->minutes();
    expect($listener()['ok'])->toBeFalse();

    config(['subtracker.reconciler.enabled' => false]);
    expect($listener())->toBeNull();
});
