<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use App\Services\Reconciler\EventRecorder;
use App\Services\Reconciler\JellyfinClient;
use App\Services\Reconciler\JellyfinEvents;
use App\Services\Reconciler\ListenerHeartbeat;
use Revolt\EventLoop;
use Throwable;

/**
 * One Jellyfin WebSocket (§17): `api_key` and a stable `deviceId` in the URL, no
 * auth headers. Plain JSON `{MessageType, Data}`; a KeepAlive goes out every
 * 30 s (the server's ForceKeepAlive is 60 s). Only LibraryChanged for the Anime
 * library is recorded.
 *
 * With RECONCILER_DEBUG, every message's type is logged before any filtering,
 * and a few seconds after connecting, which user Jellyfin attached the session
 * to: LibraryChanged is sent per user, so a session without one may never get it.
 */
final class JellyfinSession
{
    /** Seconds after connecting before asking Jellyfin about the session. */
    private const SESSION_CHECK_DELAY = 3.0;

    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly ListenerHeartbeat $heartbeat,
        private readonly JellyfinClient $client,
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
        $debug = (bool) config('subtracker.reconciler.debug');

        $timers = [EventLoop::repeat((float) config('subtracker.reconciler.jellyfin_keepalive_seconds'), function () use ($socket): void {
            $socket->send('{"MessageType":"KeepAlive"}');
            $this->heartbeat->beat('jellyfin', true);
        })];

        if ($debug) {
            $timers[] = EventLoop::delay(self::SESSION_CHECK_DELAY, fn () => $this->logSessionUser());
        }

        try {
            while (($text = $socket->receive()) !== null) {
                $message = json_decode($text, true);

                if ($debug) {
                    $this->logMessage($text, is_array($message) ? $message : null, $library);
                }

                $event = is_array($message) ? JellyfinEvents::normalize($message, $library) : null;

                if ($event !== null) {
                    $this->recorder->record('jellyfin', $event['type'], 'LibraryChanged', $event['payload']);
                }

                $this->heartbeat->beat('jellyfin', true, message: true);
            }
        } finally {
            array_map(EventLoop::cancel(...), $timers);
        }
    }

    /**
     * Debug: the message's type, before any filtering. For LibraryChanged, its
     * libraries and whether the Anime library is among them; for anything else a
     * short excerpt of its data.
     *
     * @param  array<string, mixed>|null  $message
     */
    public function logMessage(string $text, ?array $message, string $library): void
    {
        if ($message === null) {
            logger()->info('Reconciler debug: Jellyfin sent a non-JSON message', ['excerpt' => mb_substr($text, 0, 200)]);

            return;
        }

        $type = (string) ($message['MessageType'] ?? '(none)');
        $data = $message['Data'] ?? null;
        $context = ['type' => $type];

        if ($type === 'LibraryChanged' && is_array($data)) {
            $folders = array_map(fn ($id) => (string) $id, (array) ($data['CollectionFolders'] ?? []));
            $context['collectionFolders'] = $folders;
            $context['matchesAnimeLibrary'] = in_array(JellyfinEvents::id($library), array_map(JellyfinEvents::id(...), $folders), true);
            $context['itemsAdded'] = count((array) ($data['ItemsAdded'] ?? []));
        } elseif ($data !== null) {
            $context['data'] = mb_substr((string) json_encode($data), 0, 300);
        }

        logger()->info('Reconciler debug: Jellyfin message', $context);
    }

    /**
     * Debug: who Jellyfin thinks the listener is. An empty or all-zero UserId
     * means an API-key session with no user, which Jellyfin's per-user
     * LibraryChanged notifications may skip.
     */
    public function logSessionUser(): void
    {
        $deviceId = (string) config('subtracker.reconciler.jellyfin_device_id');

        try {
            $sessions = $this->client->sessionsForDevice($deviceId);
        } catch (Throwable $e) {
            logger()->warning('Reconciler debug: could not list Jellyfin sessions', ['error' => $e->getMessage()]);

            return;
        }

        logger()->info('Reconciler debug: Jellyfin sessions for the listener device', [
            'deviceId' => $deviceId,
            'sessions' => $sessions,
            'hasUser' => collect($sessions)->contains(fn (array $session) => ! in_array((string) $session['userId'], ['', '00000000000000000000000000000000', '00000000-0000-0000-0000-000000000000'], true)),
        ]);
    }
}
