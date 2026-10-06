<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

/**
 * One open WebSocket, as small as the listener needs it (§17), so sessions can be
 * tested with a scripted fake.
 */
interface ReconcilerSocket
{
    public function send(string $text): void;

    /** The next text message, or null once the connection is closed. */
    public function receive(): ?string;

    public function close(): void;
}
