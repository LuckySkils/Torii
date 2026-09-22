<?php

declare(strict_types=1);

use App\Services\Notifications\NotificationMessage;
use App\Services\Notifications\NtfyException;
use App\Services\Notifications\NtfyNotifier;
use Illuminate\Support\Facades\Http;

test('sends the full JSON body and bearer auth header', function () {
    config([
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
        'subtracker.notifications.ntfy_token' => 'secret-token',
    ]);

    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    (new NtfyNotifier)->send(new NotificationMessage(
        title: 'Title',
        message: 'Message',
        tags: ['tv'],
        priority: 3,
        clickUrl: 'http://torii.test/shows/1',
    ));

    Http::assertSent(function ($request) {
        return $request->url() === 'http://ntfy.test'
            && $request['topic'] === 'torii'
            && $request['title'] === 'Title'
            && $request['message'] === 'Message'
            && $request['tags'] === ['tv']
            && $request['priority'] === 3
            && $request['click'] === 'http://torii.test/shows/1'
            && $request->hasHeader('Authorization', 'Bearer secret-token');
    });
});

test('omits the Authorization header when no token is configured', function () {
    config([
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
        'subtracker.notifications.ntfy_token' => '',
    ]);

    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    (new NtfyNotifier)->send(new NotificationMessage(title: 'T', message: 'M'));

    Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
});

test('throws on a non-2xx response', function () {
    config([
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
    ]);

    Http::fake(['http://ntfy.test' => Http::response('bad topic', 400)]);

    (new NtfyNotifier)->send(new NotificationMessage(title: 'T', message: 'M'));
})->throws(NtfyException::class);
