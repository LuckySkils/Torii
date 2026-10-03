<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AniList descriptions are HTML, shown as excerpts by the hover card and by the
 * MCP get_anime tool. Cut to about $length characters without leaving half a tag:
 * back to before an unclosed `<`, then to the last word break. Elements left open
 * are closed by whoever renders it (the frontend's sanitizer).
 */
final class HtmlExcerpt
{
    public const DEFAULT_LENGTH = 600;

    /**
     * @return array{0: string|null, 1: bool} the excerpt, and whether it was cut
     */
    public static function cut(?string $html, int $length = self::DEFAULT_LENGTH): array
    {
        if ($html === null || mb_strlen($html) <= $length) {
            return [$html, false];
        }

        $cut = mb_substr($html, 0, $length);

        $open = mb_strrpos($cut, '<');
        if ($open !== false && mb_strrpos($cut, '>') < $open) {
            $cut = mb_substr($cut, 0, $open);
        }

        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > $length * 0.8) {
            $cut = mb_substr($cut, 0, $space);
        }

        return [rtrim($cut), true];
    }
}
