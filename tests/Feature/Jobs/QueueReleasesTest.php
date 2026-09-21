<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Jobs\QueueReleases;
use App\Models\Release;
use App\Models\Show;
use App\Services\QBittorrent\QBittorrentClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.tag' => 'subtracker',
    ]);

    Http::preventStrayRequests();
});

// A realistic 40-char hex infohash, distinct per seed. `Infohash::normalize()`
// rejects anything that isn't real 40-hex or 32-base32, so tests need one of those.
function hexHash(string $seed): string
{
    return sha1($seed);
}

function queueableShow(): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function queueableRelease(Show $show, array $overrides = []): Release
{
    static $sequence = 0;
    $sequence++;

    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-Q-'.$sequence,
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:AAAA'.$sequence,
        'infohash' => hexHash('default-'.$sequence),
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

test('marks a release exists with no add call when qbit already has the hash', function () {
    $hash = hexHash('AAAA1');

    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => $hash],
        ]), 200),
    ]);

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => $hash]);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    $release->refresh();
    expect($release->dispatch_status)->toBe(DispatchStatus::Exists)
        ->and($release->dispatched_at)->not->toBeNull();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'torrents/add'));
});

test('adds a missing hash with the category and tags asserted, then marks it sent', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/torrents/add' => Http::response('Ok.', 200),
    ]);

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => hexHash('BBBB1'), 'link' => 'magnet:?xt=urn:btih:BBBB1']);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    $release->refresh();
    expect($release->dispatch_status)->toBe(DispatchStatus::Sent)
        ->and($release->dispatched_at)->not->toBeNull();

    Http::assertSent(function ($r) {
        return str_contains($r->url(), 'torrents/add')
            && $r['urls'] === 'magnet:?xt=urn:btih:BBBB1'
            && $r['category'] === 'Anime'
            && $r['tags'] === 'subtracker';
    });
});

test('a Fails. response on one release marks only that one as error and continues with the rest', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/torrents/add' => Http::sequence()
            ->push('Fails.', 200)
            ->push('Ok.', 200),
    ]);

    $show = queueableShow();
    $bad = queueableRelease($show, ['infohash' => hexHash('CCCC1'), 'link' => 'magnet:?xt=urn:btih:CCCC1']);
    $good = queueableRelease($show, ['infohash' => hexHash('CCCC2'), 'link' => 'magnet:?xt=urn:btih:CCCC2']);

    (new QueueReleases([$bad->id, $good->id]))->handle(new QBittorrentClient);

    expect($bad->refresh()->dispatch_status)->toBe(DispatchStatus::Error)
        ->and($bad->dispatch_error)->not->toBeNull()
        ->and($good->refresh()->dispatch_status)->toBe(DispatchStatus::Sent);
});

test('a Fails. response whose hash is found on an immediate re-check is marked exists, not error', function () {
    $hash = hexHash('EEEE1');

    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::sequence()
            ->push(json_encode([]), 200)
            ->push(json_encode([['hash' => $hash]]), 200),
        'http://qbit.test:8080/api/v2/torrents/add' => Http::response('Fails.', 200),
    ]);

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => $hash, 'link' => 'magnet:?xt=urn:btih:EEEE1']);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    expect($release->refresh()->dispatch_status)->toBe(DispatchStatus::Exists)
        ->and($release->dispatch_error)->toBeNull();
});

test('a Fails. response whose hash is still missing on re-check stays an error', function () {
    $hash = hexHash('FFFF1');

    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/torrents/add' => Http::response('Fails.', 200),
    ]);

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => $hash, 'link' => 'magnet:?xt=urn:btih:FFFF1']);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    expect($release->refresh()->dispatch_status)->toBe(DispatchStatus::Error)
        ->and($release->dispatch_error)->not->toBeNull();
});

test('propagates the failure so the queue retries when qbit is unreachable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => hexHash('DDDD1')]);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);
})->throws(ConnectionException::class);

test('a release with no infohash is added directly without checking torrents/info', function () {
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/add' => Http::response('Ok.', 200),
    ]);

    $show = queueableShow();
    $release = queueableRelease($show, ['infohash' => null, 'link' => 'https://example.test/no-hash.torrent']);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    expect($release->refresh()->dispatch_status)->toBe(DispatchStatus::Sent);

    Http::assertNotSent(fn ($r) => str_contains($r->url(), 'torrents/info'));
    Http::assertSent(fn ($r) => str_contains($r->url(), 'torrents/add') && $r['urls'] === 'https://example.test/no-hash.torrent');
});

test('skips releases already sent or exists and does nothing if the whole batch is already handled', function () {
    $show = queueableShow();
    $release = queueableRelease($show, ['dispatch_status' => DispatchStatus::Sent]);

    (new QueueReleases([$release->id]))->handle(new QBittorrentClient);

    Http::assertNothingSent();
});
