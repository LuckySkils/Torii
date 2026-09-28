<?php

declare(strict_types=1);

use App\Services\Metadata\AniList\AniListClient;
use App\Services\Metadata\AniList\AniListException;
use Carbon\Carbon;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-28 12:00:00');
    Sleep::fake(syncWithCarbon: true);
});

function cloudflare429(array $headers = []): PromiseInterface
{
    return Http::response('<!DOCTYPE html><html><head><title>429 Too Many Requests</title></head><body>cloudflare</body></html>', 429, [
        'Content-Type' => 'text/html',
        ...$headers,
    ]);
}

test('posts the query and variables as JSON, with a user agent and no auth, and returns data', function () {
    Http::fake(['graphql.anilist.co' => Http::response(['data' => ['Page' => ['media' => []]]])]);

    $data = app(AniListClient::class)->query('query ($page: Int) { Page(page: $page) { media { id } } }', ['page' => 2]);

    expect($data)->toBe(['Page' => ['media' => []]]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://graphql.anilist.co'
        && $request['query'] === 'query ($page: Int) { Page(page: $page) { media { id } } }'
        && json_decode($request->body(), true)['variables'] === ['page' => 2]
        && $request->hasHeader('User-Agent', 'Torii-Subtracker/1.0')
        && $request->hasHeader('Content-Type', 'application/json')
        && ! $request->hasHeader('Authorization'));
});

test('empty variables are sent as a JSON object, not an array', function () {
    Http::fake(['graphql.anilist.co' => Http::response(['data' => []])]);

    app(AniListClient::class)->query('query { Viewer { id } }');

    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"variables":{}'));
});

test('a 429 with a Cloudflare HTML body is rate limited: it waits out Retry-After, then retries', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->pushResponse(cloudflare429(['Retry-After' => '7']))
        ->push(['data' => ['ok' => true]])]);

    $data = app(AniListClient::class)->query('query { ok }');

    expect($data)->toBe(['ok' => true]);
    Http::assertSentCount(2);
    Sleep::assertSleptTimes(1);
    Sleep::assertSequence([Sleep::for(7000)->milliseconds()]);
});

test('a non-JSON 200 body is treated as rate limited too, never as malformed data', function () {
    config(['subtracker.metadata.anilist.max_wait_seconds' => 120]);

    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->push('<html>Just a moment...</html>', 200, ['Content-Type' => 'text/html'])
        ->push(['data' => ['ok' => true]])]);

    expect(app(AniListClient::class)->query('query { ok }'))->toBe(['ok' => true]);

    // No Retry-After and no reset header: the default 60s.
    Sleep::assertSequence([Sleep::for(60000)->milliseconds()]);
});

test('without Retry-After, X-RateLimit-Reset decides the backoff', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->pushResponse(cloudflare429(['X-RateLimit-Reset' => (string) (now()->getTimestamp() + 12)]))
        ->push(['data' => ['ok' => true]])]);

    app(AniListClient::class)->query('query { ok }');

    Sleep::assertSequence([Sleep::for(12000)->milliseconds()]);
});

test('a backoff longer than the max wait throws a rate-limited exception carrying the wait, for the job to release', function () {
    Http::fake(['graphql.anilist.co' => cloudflare429(['Retry-After' => '45'])]);

    try {
        app(AniListClient::class)->query('query { ok }');
        $this->fail('Expected an AniListException.');
    } catch (AniListException $e) {
        expect($e->rateLimited)->toBeTrue()
            ->and($e->retryAfter)->toBe(45);
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('it gives up after 3 retries', function () {
    config(['subtracker.metadata.anilist.max_wait_seconds' => 120]);
    Http::fake(['graphql.anilist.co' => cloudflare429(['Retry-After' => '5'])]);

    expect(fn () => app(AniListClient::class)->query('query { ok }'))
        ->toThrow(fn (AniListException $e) => expect($e->rateLimited)->toBeTrue()->and($e->status)->toBe(429));

    Http::assertSentCount(4);
});

test('a rate limit seen by one caller pauses every other caller too', function () {
    Http::fake(['graphql.anilist.co' => Http::sequence()
        ->pushResponse(cloudflare429(['Retry-After' => '40']))
        ->push(['data' => ['ok' => true]])]);

    expect(fn () => app(AniListClient::class)->query('query { ok }'))->toThrow(AniListException::class);

    // A fresh client (another job) must not fire while the block is on.
    expect(fn () => app(AniListClient::class)->query('query { ok }'))
        ->toThrow(fn (AniListException $e) => expect($e->rateLimited)->toBeTrue());

    Http::assertSentCount(1);
});

test('GraphQL errors throw with their messages and are not rate limits', function () {
    Http::fake(['graphql.anilist.co' => Http::response([
        'errors' => [['message' => 'Cannot query field "nope" on type "Media".', 'status' => 400]],
        'data' => null,
    ], 400)]);

    expect(fn () => app(AniListClient::class)->query('query { Media { nope } }'))
        ->toThrow(fn (AniListException $e) => expect($e->rateLimited)->toBeFalse()
            ->and($e->status)->toBe(400)
            ->and($e->getMessage())->toContain('Cannot query field "nope"'));

    Http::assertSentCount(1);
});

test('the shared throttle never lets more than 25 requests through in a minute', function () {
    config(['subtracker.metadata.anilist.max_wait_seconds' => 120]);

    $sentAt = [];
    Http::fake(function () use (&$sentAt) {
        $sentAt[] = now()->getPreciseTimestamp(3) / 1000;

        return Http::response(['data' => []]);
    });

    // Two clients stand in for two jobs: the budget is shared through the cache.
    $first = app(AniListClient::class);
    $second = app(AniListClient::class);

    for ($i = 0; $i < 30; $i++) {
        ($i % 2 === 0 ? $first : $second)->query('query { ok }');
    }

    expect($sentAt)->toHaveCount(30);

    foreach ($sentAt as $i => $time) {
        $inWindow = array_filter($sentAt, fn (float $other) => $other > $time - 60 && $other <= $time);
        expect(count($inWindow))->toBeLessThanOrEqual(25, "request #{$i} exceeded the budget");
    }

    expect($sentAt[25] - $sentAt[0])->toBeGreaterThanOrEqual(60.0);
});

test('an interactive client fails fast instead of waiting for a slot, and never retries', function () {
    config(['subtracker.metadata.anilist.requests_per_minute' => 1]);
    Http::fake(['graphql.anilist.co' => Http::response(['data' => []])]);

    $client = app(AniListClient::class);
    $client->query('query { ok }');

    expect(fn () => $client->interactive()->query('query { ok }'))
        ->toThrow(fn (AniListException $e) => expect($e->rateLimited)->toBeTrue());

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});
