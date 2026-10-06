<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use App\Services\Reconciler\EventRecorder;
use App\Services\Reconciler\ListenerHeartbeat;
use App\Services\Reconciler\ShokoEvents;
use App\Services\Reconciler\SignalRReader;
use Revolt\EventLoop;
use RuntimeException;

/**
 * One Shoko SignalR connection (§17), negotiate skipped: handshake, then records
 * split on 0x1E. Server pings are answered, and a ping goes out every ~15 s.
 * Kept invocations are recorded; everything else is dropped here.
 */
final class ShokoSession
{
    public function __construct(
        private readonly EventRecorder $recorder,
        private readonly ListenerHeartbeat $heartbeat,
    ) {}

    public static function url(): string
    {
        $base = preg_replace('~^http~i', 'ws', (string) config('subtracker.reconciler.shoko_url'));

        return $base.'/signalr/aggregate?feeds=shoko,metadata,file,release&access_token='.rawurlencode((string) config('subtracker.reconciler.shoko_api_key'));
    }

    /**
     * Until the connection closes (returns) or the handshake is refused (throws).
     */
    public function run(ReconcilerSocket $socket): void
    {
        $reader = new SignalRReader;
        $handshaken = false;

        $socket->send(SignalRReader::handshake());

        $timer = EventLoop::repeat((float) config('subtracker.reconciler.shoko_ping_seconds'), function () use ($socket): void {
            $socket->send(SignalRReader::ping());
            $this->heartbeat->beat('shoko', true);
        });

        try {
            while (($frame = $socket->receive()) !== null) {
                foreach ($reader->feed($frame) as $record) {
                    if (! $handshaken) {
                        if (isset($record['error'])) {
                            throw new RuntimeException('Shoko refused the SignalR handshake: '.$record['error']);
                        }

                        $handshaken = true;
                        $this->heartbeat->beat('shoko', true);

                        continue;
                    }

                    match ($record['type'] ?? null) {
                        SignalRReader::PING => $socket->send(SignalRReader::ping()),
                        SignalRReader::CLOSE => $socket->close(),
                        SignalRReader::INVOCATION => $this->invocation($record),
                        default => null,
                    };
                }

                $this->heartbeat->beat('shoko', true, message: true);
            }
        } finally {
            EventLoop::cancel($timer);
        }
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function invocation(array $record): void
    {
        $target = (string) ($record['target'] ?? '');
        $event = ShokoEvents::normalize($target, (array) ($record['arguments'] ?? []));

        if ($event !== null) {
            $this->recorder->record('shoko', $event['type'], $target, $event['payload']);
        }
    }
}
