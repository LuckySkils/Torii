<?php

declare(strict_types=1);

use App\Models\FeedPoll;
use App\Models\Show;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'subtracker.feed.url' => 'https://subsplease.org/rss/?r=1080',
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => 'admin',
        'subtracker.qbittorrent.password' => 'secret',
        'subtracker.qbittorrent.category' => 'Anime',
        'subtracker.qbittorrent.rule_prefix' => '[ST] ',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
    ]);
});

test('POST /feed/poll forces an immediate poll, records it, and flashes a success summary', function () {
    Http::fake([
        'https://subsplease.org/rss/*' => Http::response('<rss><channel></channel></rss>', 200),
    ]);

    $response = $this->post('/feed/poll');

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Feed poll ran: 0 new releases.');
    expect(FeedPoll::count())->toBe(1);
});

test('POST /feed/poll flashes an error when the poll itself fails', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $response = $this->post('/feed/poll');

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Feed poll failed: Connection timed out');
});

test('POST /qbit/reconcile runs a reconcile pass and flashes a summary', function () {
    Show::create([
        'name' => 'Grand Blue S3',
        'slug' => 'grand-blue-s3',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
        'is_tracked' => true,
    ]);

    Http::fake([
        'http://qbit.test:8080/api/v2/rss/rules' => Http::response(json_encode([]), 200),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    $response = $this->post('/qbit/reconcile');

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Reconciled: 1 created, 0 updated, 0 disabled, 0 orphaned rules left alone.');
    Http::assertSent(fn ($r) => str_contains($r->url(), 'setRule') && ($r['ruleName'] ?? null) === '[ST] Grand Blue S3');
});

test('POST /qbit/reconcile flashes an error when qbit is unreachable', function () {
    Http::fake(function () {
        throw new ConnectionException('Connection refused');
    });

    $response = $this->post('/qbit/reconcile');

    $response->assertRedirect();
    $response->assertSessionHas('error');
});
