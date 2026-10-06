<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

/**
 * SignalR's JSON protocol, the little the listener needs (§17): records are JSON
 * objects each ended by the record separator 0x1E; a WebSocket frame may hold
 * several, or end mid-record (kept for the next frame). Type 1 = invocation
 * (`target`, `arguments`), 6 = ping, 7 = close; the handshake reply is `{}` or
 * `{"error": …}`.
 */
final class SignalRReader
{
    public const SEPARATOR = "\x1e";

    public const INVOCATION = 1;

    public const PING = 6;

    public const CLOSE = 7;

    private string $buffer = '';

    public static function handshake(): string
    {
        return '{"protocol":"json","version":1}'.self::SEPARATOR;
    }

    public static function ping(): string
    {
        return '{"type":6}'.self::SEPARATOR;
    }

    /**
     * The complete records in this frame (plus whatever an earlier frame left
     * unfinished), decoded. Unparseable records are skipped.
     *
     * @return array<int, array<string, mixed>>
     */
    public function feed(string $frame): array
    {
        $parts = explode(self::SEPARATOR, $this->buffer.$frame);
        $this->buffer = (string) array_pop($parts);

        $records = [];

        foreach ($parts as $part) {
            if (trim($part) === '') {
                continue;
            }

            $record = json_decode($part, true);

            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }
}
