<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use App\Services\Reconciler\ListenerHeartbeat;
use Closure;
use Throwable;

/**
 * Keeps one connection up forever (§17): connect, run the session until the
 * socket closes or fails, then reconnect after 1 s, doubling to 60 s; a
 * successful connection resets the delay. Every connect and disconnect is logged
 * and written to the heartbeat. Stops once `$shouldStop()` says so.
 */
final class ConnectionSupervisor
{
    private const MAX_DELAY = 60;

    /**
     * @param  Closure(float): void  $sleep  waits without blocking the event loop
     */
    public function __construct(
        private readonly SocketConnector $connector,
        private readonly ListenerHeartbeat $heartbeat,
        private readonly Closure $sleep,
    ) {}

    /**
     * @param  array<string, string>  $headers
     * @param  callable(ReconcilerSocket): void  $session
     * @param  Closure(): bool  $shouldStop
     * @param  Closure(ReconcilerSocket|null): void  $onSocket  the open socket (null when closed), so a stop can close it
     */
    public function run(string $name, string $url, array $headers, callable $session, Closure $shouldStop, Closure $onSocket): void
    {
        $delay = 1;

        while (! $shouldStop()) {
            try {
                $socket = $this->connector->connect($url, $headers);
                $onSocket($socket);
                logger()->info("Reconciler listener: {$name} connected");
                $delay = 1;

                $session($socket);

                logger()->warning("Reconciler listener: {$name} disconnected");
            } catch (Throwable $e) {
                logger()->warning("Reconciler listener: {$name} connection failed", ['error' => $e->getMessage()]);
            } finally {
                $onSocket(null);
            }

            $this->heartbeat->beat($name, false);

            if ($shouldStop()) {
                break;
            }

            ($this->sleep)((float) $delay);
            $delay = min($delay * 2, self::MAX_DELAY);
        }
    }
}
