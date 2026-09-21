<?php

declare(strict_types=1);

use App\Services\QBittorrent\QBittorrentClient;
use App\Services\QBittorrent\QBittorrentException;
use Illuminate\Support\Facades\Http;

function configureQbit(string $username = 'admin', string $password = 'secret'): void
{
    config([
        'subtracker.qbittorrent.url' => 'http://qbit.test:8080',
        'subtracker.qbittorrent.username' => $username,
        'subtracker.qbittorrent.password' => $password,
    ]);
}

test('logs in and caches the SID cookie before the first authenticated call', function () {
    configureQbit();
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/app/version' => Http::response('v5.0.1', 200),
    ]);

    $version = (new QBittorrentClient)->getVersion();

    expect($version)->toBe('v5.0.1');

    Http::assertSent(fn ($request) => $request->url() === 'http://qbit.test:8080/api/v2/auth/login'
        && $request['username'] === 'admin'
        && $request['password'] === 'secret'
        && $request->hasHeader('Referer', 'http://qbit.test:8080'));

    Http::assertSent(fn ($request) => $request->url() === 'http://qbit.test:8080/api/v2/app/version'
        && $request->header('Cookie')[0] === 'SID=abc123'
        && $request->hasHeader('Referer', 'http://qbit.test:8080'));
});

test('never logs in when username and password are both empty', function () {
    configureQbit(username: '', password: '');
    Http::preventStrayRequests();
    Http::fake([
        'http://qbit.test:8080/api/v2/app/version' => Http::response('v5.0.1', 200),
    ]);

    (new QBittorrentClient)->getVersion();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'auth/login'));
});

test('throws when the login body is Fails. even on a 200 status', function () {
    configureQbit();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Fails.', 200),
    ]);

    (new QBittorrentClient)->getVersion();
})->throws(QBittorrentException::class);

test('throws when login returns 403 for a banned ip', function () {
    configureQbit();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('', 403),
    ]);

    (new QBittorrentClient)->getVersion();
})->throws(QBittorrentException::class);

test('re-logs in once and retries on a 403 from a non-login call', function () {
    configureQbit();
    Http::preventStrayRequests();

    $loginCalls = 0;

    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::sequence()
            ->push('Ok.', 200, ['Set-Cookie' => 'SID=first; path=/'])
            ->push('Ok.', 200, ['Set-Cookie' => 'SID=second; path=/']),
        'http://qbit.test:8080/api/v2/app/version' => Http::sequence()
            ->push('', 403)
            ->push('v5.0.1', 200),
    ]);

    $version = (new QBittorrentClient)->getVersion();

    expect($version)->toBe('v5.0.1');

    Http::assertSentCount(4);
});

test('throws with the endpoint and body for a non-2xx response', function () {
    configureQbit();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/torrents/categories' => Http::response('server error', 500),
    ]);

    try {
        (new QBittorrentClient)->getCategories();
        $this->fail('Expected a QBittorrentException to be thrown.');
    } catch (QBittorrentException $e) {
        expect($e->endpoint)->toBe('/api/v2/torrents/categories')
            ->and($e->responseBody)->toBe('server error')
            ->and($e->status)->toBe(500);
    }
});

test('sends the rule definition as a JSON string in a form-encoded post', function () {
    configureQbit();
    Http::fake([
        'http://qbit.test:8080/api/v2/auth/login' => Http::response('Ok.', 200, ['Set-Cookie' => 'SID=abc123; path=/']),
        'http://qbit.test:8080/api/v2/rss/setRule' => Http::response('', 200),
    ]);

    (new QBittorrentClient)->setRssRule('[ST] __selftest', ['enabled' => false, 'mustContain' => 'selftest']);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://qbit.test:8080/api/v2/rss/setRule'
            && $request['ruleName'] === '[ST] __selftest'
            && $request['ruleDef'] === json_encode(['enabled' => false, 'mustContain' => 'selftest']);
    });
});
