<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use App\Services\Reconciler\EventRecorder;
use App\Services\Reconciler\JellyfinEvents;
use App\Services\Reconciler\ListenerHeartbeat;
use Revolt\EventLoop;

/**
 * One Jellyfin WebSocket (§17): `api_key` and a stable `deviceId` in the URL, no
 * auth headers. Plain JSON `{MessageType, Data}`; a KeepAlive goes out every
 * 30 s (the server's ForceKeepAlive is 60 s). Only LibraryChanged for the Anime
 * library is recorded.
 */
final class JellyfinSession
{
    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly ListenerHeartbeat $heartbeat,
    ) {}

    public static function url(): string
    {
        $base = preg_replace('~^http~i', 'ws', (string) config('subtracker.reconciler.jellyfin_url'));

        return $base.'/socket?'.http_build_query([
            'api_key' => (string) config('subtracker.reconciler.jellyfin_api_key'),
            'deviceId' => (string) config('subtracker.reconciler.jellyfin_device_id'),
        ]);
    }

    public function run(ReconcilerSocket $socket): void
    {
        $this->heartbeat->beat('jellyfin', true);
        $library = (string) config('subtracker.reconciler.jellyfin_anime_library_id');

        $timer = EventLoop::repeat((float) config('subtracker.reconciler.jellyfin_keepalive_seconds'), function () use ($socket): void {
            $socket->send('{"MessageType":"KeepAlive"}');
            $this->heartbeat->beat('jellyfin', true);
        });

        try {
            while (($text = $socket->receive()) !== null) {
                $message = json_decode($text, true);
                $event = is_array($message) ? JellyfinEvents::normalize($message, $library) : null;

                if ($event !== null) {
                    $this->recorder->record('jellyfin', $event['type'], 'LibraryChanged', $event['payload']);
                }

                $this->heartbeat->beat('jellyfin', true, message: true);
            }
        } finally {
            EventLoop::cancel($timer);
        }
    }
}
