<?php

declare(strict_types=1);

namespace App\Services\Reconciler\Listener;

use Throwable;

interface SocketConnector
{
    /**
     * @param  array<string, string>  $headers
     *
     * @throws Throwable when the connection can't be opened
     */
    public function connect(string $url, array $headers = []): ReconcilerSocket;
}
