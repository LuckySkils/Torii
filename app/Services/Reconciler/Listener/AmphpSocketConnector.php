<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;

use function Amp\Websocket\Client\connect;

/**
 * Real WebSockets through amphp/websocket-client (§17): fibers on the Revolt
 * event loop, so both connections run in one process as plain sequential code.
 */
final class AmphpSocketConnector implements SocketConnector
{
    public function connect(string $url, array $headers = []): ReconcilerSocket
    {
        $connection = connect((new WebsocketHandshake($url, $headers))->withTcpConnectTimeout(15)->withTlsHandshakeTimeout(15));

        return new class($connection) implements ReconcilerSocket
        {
            public function __construct(private readonly WebsocketConnection $connection) {}

            public function send(string $text): void
            {
                $this->connection->sendText($text);
            }

            public function receive(): ?string
            {
                return $this->connection->receive()?->buffer();
            }

            public function close(): void
            {
                if (! $this->connection->isClosed()) {
                    $this->connection->close();
                }
            }
        };
    }
}
