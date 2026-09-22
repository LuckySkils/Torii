<?php

declare(strict_types=1);

use App\Events\ReleaseDownloaded;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

function completedCheckShow(): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => true,
    ]);
}

function completedCheckRelease(Show $show, string $seed, array $overrides = []): Release
{
    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-CC-'.$seed,
        'title' => 'irrelevant',
        'episode' => '05',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$seed,
        'infohash' => sha1($seed),
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

function configureQbitForCompletedCheck(): void
{
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
    ]);
}

test('matches a completed torrent by hash, case-insensitively, and records downloaded_at', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'AAAA');

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => strtoupper(sha1('AAAA')), 'completion_on' => now()->subMinutes(5)->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect($release->refresh()->downloaded_at)->not->toBeNull();
});

test('matches a completed torrent whose reported hash is base32', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    $bytes = random_bytes(20);
    $hex = bin2hex($bytes);
    $base32 = base32EncodeForTest($bytes);

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'BASE32', ['infohash' => $hex]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => $base32, 'completion_on' => now()->subMinutes(5)->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect($release->refresh()->downloaded_at)->not->toBeNull();
});

test('the first run records completions silently, without firing any event', function () {
    configureQbitForCompletedCheck();
    Cache::forget('completion_sync_initialized');

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'FIRSTRUN');

    Event::fake();
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('FIRSTRUN'), 'completion_on' => now()->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect($release->refresh()->downloaded_at)->not->toBeNull();
    Event::assertNotDispatched(ReleaseDownloaded::class);
    expect(Cache::has('completion_sync_initialized'))->toBeTrue();
});

test('later runs fire ReleaseDownloaded for a recent completion', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'RECENT');

    Event::fake();
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('RECENT'), 'completion_on' => now()->subHours(2)->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    Event::assertDispatched(ReleaseDownloaded::class, fn ($event) => $event->release->is($release));
});

test('a completion older than 24 hours is recorded but does not fire an event', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'OLD');

    Event::fake();
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('OLD'), 'completion_on' => now()->subDays(3)->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect($release->refresh()->downloaded_at)->not->toBeNull();
    Event::assertNotDispatched(ReleaseDownloaded::class);
});

test('downloaded_at is never overwritten once set', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    $show = completedCheckShow();
    $release = completedCheckRelease($show, 'IDEMPOTENT', ['downloaded_at' => now()->subDays(10)]);
    // Postgres truncates the timestamp column's fractional seconds, so compare
    // against the already-persisted (and thus already-truncated) value.
    $originalTime = $release->refresh()->downloaded_at;

    Event::fake();
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('IDEMPOTENT'), 'completion_on' => now()->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect($release->refresh()->downloaded_at->equalTo($originalTime))->toBeTrue();
    Event::assertNotDispatched(ReleaseDownloaded::class);
});

test('exits cleanly and logs the error when qbit is unreachable', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $this->artisan('qbit:check-completed')->assertFailed();
});

test('an unmatched torrent in the same category is ignored', function () {
    configureQbitForCompletedCheck();
    Cache::put('completion_sync_initialized', true);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('UNMATCHED'), 'completion_on' => now()->timestamp],
        ]), 200),
    ]);

    $this->artisan('qbit:check-completed')->assertSuccessful();

    expect(Release::count())->toBe(0);
});
