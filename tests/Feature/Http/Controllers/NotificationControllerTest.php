<?php

declare(strict_types=1);

use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Http;

test('sends a test notification and flashes success when enabled', function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
    ]);
    Http::preventStrayRequests();
    Http::fake(['http://ntfy.test' => Http::response('Ok.', 200)]);

    $response = $this->post('/notifications/test');

    $response->assertRedirect();
    $response->assertSessionHas('success', 'Test notification sent.');
    expect(NotificationLog::sole()->status)->toBe(NotificationStatus::Sent);
});

test('flashes an error and logs it when the send fails', function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
    ]);
    Http::fake(['http://ntfy.test' => Http::response('bad request', 400)]);

    $response = $this->post('/notifications/test');

    $response->assertRedirect();
    $response->assertSessionHas('error');
    expect(NotificationLog::sole()->status)->toBe(NotificationStatus::Error);
});

test('flashes an error and sends nothing when notifications are disabled', function () {
    config(['subtracker.notifications.enabled' => false]);
    Http::preventStrayRequests();

    $response = $this->post('/notifications/test');

    $response->assertRedirect();
    $response->assertSessionHas('error', 'Notifications are disabled.');
    expect(NotificationLog::count())->toBe(0);
});
