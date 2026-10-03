<?php

declare(strict_types=1);

namespace App\Mcp;

/**
 * Last line of defence for "no config secret in MCP output": tools and resources
 * never select a secret, but stored error text (rule_error, dispatch_error) comes
 * from exception messages, so every configured secret is masked in all strings.
 */
final class Redactor
{
    private const SECRETS = [
        'subtracker.qbittorrent.password',
        'subtracker.notifications.ntfy_token',
        'subtracker.mcp.token',
        'app.key',
        'database.connections.pgsql.password',
        'database.redis.default.password',
    ];

    /**
     * @template T
     *
     * @param  T  $data
     * @return T
     */
    public static function clean(mixed $data): mixed
    {
        $secrets = array_values(array_filter(
            array_map(fn (string $key) => (string) config($key), self::SECRETS),
            fn (string $secret) => strlen($secret) >= 4,
        ));

        if ($secrets === []) {
            return $data;
        }

        array_walk_recursive($data, function (mixed &$value) use ($secrets): void {
            if (is_string($value)) {
                $value = str_replace($secrets, '[redacted]', $value);
            }
        });

        return $data;
    }
}
