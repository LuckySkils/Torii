<?php

declare(strict_types=1);

use App\Models\FeedPoll;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

test('force fetches immediately and records a poll on a fresh feed', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('subtracker.feed.url') => Http::response(
            file_get_contents(dirname(__DIR__, 2).'/Fixtures/subsplease_1080.xml'),
            200,
            ['ETag' => '"v1"', 'Last-Modified' => 'Mon, 21 Sep 2026 08:00:00 GMT'],
        ),
    ]);

    $this->artisan('feed:poll', ['--force' => true])->assertExitCode(0);

    $poll = FeedPoll::sole();

    expect($poll->not_modified)->toBeFalse()
        ->and($poll->http_status)->toBe(200)
        ->and($poll->items_total)->toBe(50)
        ->and($poll->items_new)->toBe(50)
        ->and($poll->etag)->toBe('"v1"');
});

test('skips fetching when the scheduled interval has not elapsed', function () {
    Http::preventStrayRequests();
    Cache::put('feed:next_poll_at', now()->addMinutes(10));

    $this->artisan('feed:poll')->assertExitCode(0);

    expect(FeedPoll::count())->toBe(0);
    Http::assertNothingSent();
});

test('records a not_modified poll on a 304 response', function () {
    Http::preventStrayRequests();
    Http::fake([
        config('subtracker.feed.url') => Http::response('', 304),
    ]);

    $this->artisan('feed:poll', ['--force' => true])->assertExitCode(0);

    $poll = FeedPoll::sole();

    expect($poll->not_modified)->toBeTrue()
        ->and($poll->http_status)->toBe(304)
        ->and($poll->items_total)->toBe(0);
});

test('records the error and still schedules the next poll on a connection failure', function () {
    Http::preventStrayRequests();
    Http::fake(function () {
        throw new ConnectionException('Connection timed out');
    });

    $this->artisan('feed:poll', ['--force' => true])->assertExitCode(1);

    $poll = FeedPoll::sole();

    expect($poll->error)->toBe('Connection timed out')
        ->and($poll->http_status)->toBeNull();

    expect(Cache::get('feed:next_poll_at'))->not->toBeNull();
});
