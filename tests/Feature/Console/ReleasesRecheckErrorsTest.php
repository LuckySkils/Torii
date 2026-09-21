<?php

declare(strict_types=1);

use App\Enums\DispatchStatus;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Http;

function recheckableShow(): Show
{
    return Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
}

function recheckableRelease(Show $show, string $seed, array $overrides = []): Release
{
    return Release::create(array_merge([
        'show_id' => $show->id,
        'guid' => 'GUID-RC-'.$seed,
        'title' => 'irrelevant',
        'is_batch' => false,
        'resolution' => '1080p',
        'link' => 'magnet:?xt=urn:btih:'.$seed,
        'infohash' => sha1($seed),
        'dispatch_status' => DispatchStatus::Error,
        'dispatch_error' => 'qBittorrent request to [/api/v2/torrents/add] failed (status: 200): Fails.',
        'published_at' => now(),
        'first_seen_at' => now(),
    ], $overrides));
}

test('flips error releases that qbit already has to exists, and leaves the rest as error', function () {
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
    ]);

    $show = recheckableShow();
    $found = recheckableRelease($show, 'FOUND');
    $stillMissing = recheckableRelease($show, 'MISSING');
    $notAnError = recheckableRelease($show, 'OK', ['dispatch_status' => DispatchStatus::Sent, 'dispatch_error' => null]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc; path=/']),
        'http://qbit.test:8080/api/v2/torrents/info*' => Http::response(json_encode([
            ['hash' => sha1('FOUND')],
        ]), 200),
    ]);

    $this->artisan('releases:recheck-errors')->assertSuccessful();

    expect($found->refresh()->dispatch_status)->toBe(DispatchStatus::Exists)
        ->and($found->dispatch_error)->toBeNull()
        ->and($found->dispatched_at)->not->toBeNull()
        ->and($stillMissing->refresh()->dispatch_status)->toBe(DispatchStatus::Error)
        ->and($notAnError->refresh()->dispatch_status)->toBe(DispatchStatus::Sent);
});

test('does nothing when there are no error releases with an infohash', function () {
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
    ]);

    Http::preventStrayRequests();

    $this->artisan('releases:recheck-errors')->assertSuccessful();

    Http::assertNothingSent();
});
