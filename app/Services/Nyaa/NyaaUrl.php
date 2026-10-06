<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

/**
 * The only URLs the Nyaa import fetches: https://nyaa.si/ with page=rss. This
 * endpoint fetches a user-supplied URL server-side, so the host check is what
 * keeps it from reaching other addresses on the network. Redirect targets go
 * through the same check (NyaaClient).
 */
final class NyaaUrl
{
    public const HOST = 'nyaa.si';

    /**
     * @return string the URL, trimmed
     *
     * @throws NyaaException when it isn't a Nyaa RSS link
     */
    public static function check(string $url): string
    {
        $url = trim($url);
        $parts = parse_url($url);

        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== self::HOST) {
            throw new NyaaException('Only https://nyaa.si/ links can be imported.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new NyaaException('Only plain https://nyaa.si/ links can be imported (no credentials or other ports).');
        }

        if (! in_array($parts['path'] ?? '/', ['', '/'], true)) {
            throw new NyaaException('That is not a Nyaa search RSS link: it should start with https://nyaa.si/?page=rss.');
        }

        parse_str($parts['query'] ?? '', $query);

        if (($query['page'] ?? null) !== 'rss') {
            throw new NyaaException('That is a Nyaa page, not its RSS feed: the link needs page=rss (use the RSS button on a Nyaa search).');
        }

        return $url;
    }
}
