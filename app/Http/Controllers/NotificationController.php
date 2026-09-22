<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Contracts\Notifier;
use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use App\Models\NotificationLog;
use App\Services\Notifications\NotificationMessage;
use Illuminate\Http\RedirectResponse;
use Throwable;

class NotificationController extends Controller
{
    public function test(Notifier $notifier): RedirectResponse
    {
        if (! config('subtracker.notifications.enabled')) {
            return back()->with('error', 'Notifications are disabled.');
        }

        try {
            $notifier->send(new NotificationMessage(
                title: 'Torii test notification',
                message: 'If you can see this, ntfy is configured correctly.',
                tags: ['test_tube'],
            ));

            NotificationLog::create([
                'kind' => NotificationKind::Test,
                'release_id' => null,
                'status' => NotificationStatus::Sent,
                'sent_at' => now(),
            ]);

            return back()->with('success', 'Test notification sent.');
        } catch (Throwable $e) {
            NotificationLog::create([
                'kind' => NotificationKind::Test,
                'release_id' => null,
                'status' => NotificationStatus::Error,
                'error' => $e->getMessage(),
                'sent_at' => now(),
            ]);

            return back()->with('error', "Failed to send test notification: {$e->getMessage()}");
        }
    }
}
