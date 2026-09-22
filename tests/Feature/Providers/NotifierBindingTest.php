<?php

declare(strict_types=1);

use App\Contracts\Notifier;
use App\Services\Notifications\NtfyNotifier;
use App\Services\Notifications\NullNotifier;

test('resolves to NullNotifier when the URL is empty', function () {
    config([
        'subtracker.notifications.enabled' => false,
        'subtracker.notifications.ntfy_url' => '',
    ]);

    expect(app(Notifier::class))->toBeInstanceOf(NullNotifier::class);
});

test('resolves to NtfyNotifier when enabled', function () {
    config([
        'subtracker.notifications.enabled' => true,
        'subtracker.notifications.ntfy_url' => 'http://ntfy.test',
        'subtracker.notifications.ntfy_topic' => 'torii',
    ]);

    expect(app(Notifier::class))->toBeInstanceOf(NtfyNotifier::class);
});
