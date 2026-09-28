<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use Illuminate\Support\Str;

/**
 * Pure normalization for comparing SubsPlease show names with provider titles.
 *
 * `plain`: lowercase, accents folded (Caraméliser → carameliser), group/resolution/
 * (TV) tags dropped, punctuation stripped, whitespace collapsed. `base` is `plain`
 * with the season marker removed and `season` holds its number, so `S2`,
 * `2nd Season`, `Season 2` and a trailing `II` all become season 2 of the same base.
 */
final class TitleNormalizer
{
    private const ROMAN = ['II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];

    private const MARKERS = [
        '/\b(\d+)(?:st|nd|rd|th) season\b/u',
        '/\bseason (\d+)\b/u',
        '/\bs(\d+)\b/u',
    ];

    public function normalize(string $title): NormalizedTitle
    {
        $title = preg_replace('/\[[^\]]*\]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\((?:tv|\d{3,4}p)\)|\b\d{3,4}p\b/iu', ' ', $title) ?? $title;

        $plain = $this->clean($title);

        $roman = $this->romanSeason($title);

        if ($roman !== null) {
            return new NormalizedTitle($plain, $this->clean($roman[0]), $roman[1]);
        }

        foreach (self::MARKERS as $pattern) {
            if (preg_match($pattern, $plain, $match) === 1) {
                return new NormalizedTitle($plain, $this->collapse(str_replace($match[0], ' ', $plain)), (int) $match[1]);
            }
        }

        return new NormalizedTitle($plain, $plain, null);
    }

    /**
     * The title as written, minus its season marker ("Link Click S3" → "Link Click",
     * "Mushoku Tensei III: …" → "Mushoku Tensei: …"), for a provider search that
     * would otherwise take "S3" literally. Everything else keeps its casing and
     * punctuation. The season number itself is normalize()->season.
     */
    public function withoutSeasonMarker(string $title): string
    {
        $roman = $this->romanSeason($title);

        if ($roman !== null) {
            $stripped = $roman[0];
        } else {
            $stripped = $title;

            foreach (['/\b\d+(?:st|nd|rd|th)\s+season\b/iu', '/\bseason\s+\d+\b/iu', '/\bs\d+\b/iu'] as $pattern) {
                $result = preg_replace($pattern, ' ', $title, 1, $count);

                if ($count > 0 && $result !== null) {
                    $stripped = $result;
                    break;
                }
            }
        }

        $stripped = preg_replace('/\s+([:,.!?])/u', '$1', $stripped) ?? $stripped;
        $stripped = trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped, " \t-–:");

        return $stripped === '' ? trim($title) : $stripped;
    }

    /**
     * An uppercase roman numeral (II–X) as its own word, at the end or right
     * before a colon or dash, e.g. "Youjo Senki II", "Mushoku Tensei III: ...".
     * Case-sensitive, so romaji words like "ii" don't count; "Part II" doesn't either.
     *
     * @return array{0: string, 1: int}|null the title without the numeral, and the season
     */
    private function romanSeason(string $title): ?array
    {
        $pattern = '/(?<![\p{L}\p{N}])(?<!Part )('.implode('|', array_keys(self::ROMAN)).')(?=\s*(?:[:\-–]|$))/u';

        if (preg_match($pattern, rtrim($title), $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$numeral, $offset] = $match[1];

        return [substr($title, 0, $offset).' '.substr($title, $offset + strlen($numeral)), self::ROMAN[$numeral]];
    }

    private function clean(string $value): string
    {
        // Only Latin letters are folded; native (kana/kanji) titles stay as they are.
        $value = preg_replace_callback('/\p{Latin}+/u', fn (array $m) => Str::ascii($m[0]), $value) ?? $value;
        $value = mb_strtolower($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;

        return $this->collapse($value);
    }

    private function collapse(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}
