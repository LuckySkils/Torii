<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

/**
 * Builds a magnet from a Nyaa item instead of using its .torrent URL, so
 * qBittorrent never contacts Nyaa and dispatch works like SubsPlease's magnets:
 * `magnet:?xt=urn:btih:<hash>&dn=<title>&tr=<tracker>…`, each part encoded with
 * rawurlencode (spaces as %20, not +), trackers from NYAA_TRACKERS in order.
 */
final class MagnetLink
{
    /**
     * @param  string  $infohash  40 lowercase hex (Infohash::normalize)
     */
    public static function build(string $infohash, string $title): string
    {
        $trackers = array_map(
            fn (string $tracker) => '&tr='.rawurlencode($tracker),
            (array) config('subtracker.nyaa.trackers'),
        );

        return 'magnet:?xt=urn:btih:'.$infohash.'&dn='.rawurlencode($title).implode('', $trackers);
    }
}
