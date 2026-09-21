<?php

declare(strict_types=1);

namespace App\Services\Feed;

final class Infohash
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Normalizes a BitTorrent infohash to 40-character lowercase hex.
     *
     * Magnet `xt=urn:btih:` values come in two shapes: 40-char hex, or the
     * 32-char base32 encoding of the same 20 bytes (this is what SubsPlease's
     * feed actually uses). qBittorrent always reports hashes as lowercase hex,
     * so anything compared against it must be normalized to that form first.
     */
    public static function normalize(?string $hash): ?string
    {
        if ($hash === null) {
            return null;
        }

        $hash = trim($hash);

        if ($hash === '') {
            return null;
        }

        if (strlen($hash) === 40 && ctype_xdigit($hash)) {
            return strtolower($hash);
        }

        if (strlen($hash) === 32) {
            return self::base32ToHex($hash);
        }

        return null;
    }

    private static function base32ToHex(string $base32): ?string
    {
        $base32 = strtoupper($base32);
        $bits = '';

        foreach (str_split($base32) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                return null;
            }

            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        // 32 base32 chars * 5 bits = 160 bits = 20 bytes, dividing evenly into
        // 40 hex nibbles with nothing left over to pad or trim.
        $hex = '';

        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return strtolower($hex);
    }
}
