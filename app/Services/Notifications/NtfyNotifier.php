<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Contracts\Notifier;
use Illuminate\Support\Facades\Http;

final class NtfyNotifier implements Notifier
{
    public function send(NotificationMessage $message): void
    {
        $headers = [];
        $token = (string) config('subtracker.notifications.ntfy_token');

        if ($token !== '') {
            $headers['Authorization'] = "Bearer {$token}";
        }

        $payload = [
            'topic' => (string) config('subtracker.notifications.ntfy_topic'),
            'title' => $message->title,
            'message' => $message->message,
        ];

        if ($message->tags !== []) {
            $payload['tags'] = $message->tags;
        }

        if ($message->priority !== null) {
            $payload['priority'] = $message->priority;
        }

        if ($message->clickUrl !== null) {
            $payload['click'] = $message->clickUrl;
        }

        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->post((string) config('subtracker.notifications.ntfy_url'), $payload);

        if (! $response->successful()) {
            throw new NtfyException("ntfy request failed (status: {$response->status()}): {$response->body()}");
        }
    }
}
