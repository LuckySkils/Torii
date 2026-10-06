<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use Illuminate\Support\Facades\Cache;

/**
 * The listener's liveness, per connection (§17), in the shared cache so the main
 * container's health checks can read it: connected or not, the last message, and
 * when the listener last wrote (a stale write means the listener itself is gone).
 */
final class ListenerHeartbeat
{
    public const CONNECTIONS = ['shoko', 'jellyfin'];

    public function beat(string $connection, bool $connected, bool $message = false): void
    {
        $previous = $this->get($connection);

        Cache::put($this->key($connection), [
            'connected' => $connected,
            'lastMessageAt' => $message ? now()->toIso8601String() : ($previous['lastMessageAt'] ?? null),
            'since' => ($previous['connected'] ?? null) === $connected ? ($previous['since'] ?? now()->toIso8601String()) : now()->toIso8601String(),
            'updatedAt' => now()->toIso8601String(),
        ], now()->addDay());
    }

    /**
     * @return array{connected: bool, lastMessageAt: string|null, since: string|null, updatedAt: string}|null
     */
    public function get(string $connection): ?array
    {
        $value = Cache::get($this->key($connection));

        return is_array($value) ? $value : null;
    }

    /**
     * Each connection's state, `fresh` false when the listener hasn't written lately.
     *
     * @return array<string, array{connected: bool, fresh: bool, lastMessageAt: string|null, since: string|null}>
     */
    public function status(): array
    {
        $stale = now()->subSeconds((int) config('subtracker.reconciler.heartbeat_stale_seconds'));
        $status = [];

        foreach (self::CONNECTIONS as $connection) {
            $beat = $this->get($connection);
            $status[$connection] = [
                'connected' => (bool) ($beat['connected'] ?? false),
                'fresh' => $beat !== null && now()->parse($beat['updatedAt'])->greaterThan($stale),
                'lastMessageAt' => $beat['lastMessageAt'] ?? null,
                'since' => $beat['since'] ?? null,
            ];
        }

        return $status;
    }

    private function key(string $connection): string
    {
        return "reconciler:heartbeat:{$connection}";
    }
}
