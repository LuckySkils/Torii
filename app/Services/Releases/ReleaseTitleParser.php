<?php

declare(strict_types=1);

namespace App\Services\Releases;

use App\Services\Feed\SubsPleaseTitleParser;

/**
 * Reads a release title from any fansub/rip group: `[Group] Name - 06 [1080p]…`,
 * `[Group] Name - S02E06 […]`, `[Group] Name S01E06 1080p … (Alt Title, Dual-Audio)`,
 * batches (`(01-12)`, `- 01 ~ 12`, `S01E01-E12`, a season pack `S02`, a `Batch`
 * token) and dot-separated scene names (`Name.S01E06.1080p.WEB-GROUP`). Pure, no I/O.
 *
 * Titles starting with `[SubsPlease]` go through SubsPleaseTitleParser, so they
 * read exactly as the feed reads them (the season stays inside the name).
 *
 * When no episode or batch marker is found the title is unparsed (`name` null):
 * an episode is never guessed.
 */
final class ReleaseTitleParser
{
    public const SUBSPLEASE = 'SubsPlease';

    private const EXTENSION = '/\.(?:mkv|mp4|avi|webm|m4v|ts|wmv)$/i';

    private const RESOLUTION = '/(?<![0-9])(480|540|720|1080|2160)p(?![a-z0-9])/i';

    private const CRC = '/\[([0-9A-Fa-f]{8})\]/';

    /** `S02E06`, `S02E06v2`, `S01E01-E12` / `S01E01-12` (a batch). */
    private const SEASON_EPISODE = '/(?<![A-Za-z0-9])S(\d{1,2})E(\d{1,4}(?:\.\d+)?)(?:v(\d+))?(?:\s*-\s*(?:S\d{1,2})?E?(\d{1,4}))?(?![A-Za-z0-9])/i';

    /** ` - 01 ~ 12`, ` - 01-12`. */
    private const DASH_RANGE = '/\s-\s+(\d{1,4})\s*[~-]\s*(\d{1,4})(?![A-Za-z0-9.])/';

    /** ` - 06`, ` - 06v2`, ` - 12.5`. */
    private const DASH_EPISODE = '/\s-\s+(\d{1,4}(?:\.\d+)?)(?:v(\d+))?(?=\s|$)/';

    /** A standalone `E06` / `EP06`. */
    private const E_EPISODE = '/(?<![A-Za-z0-9])(?:E|EP|Ep)(\d{1,4}(?:\.\d+)?)(?:v(\d+))?(?=\s|$)/';

    /** `(01-12)`, as SubsPlease writes batches. */
    private const PAREN_RANGE = '/\((\d{1,4})\s*[~-]\s*(\d{1,4})\)/';

    /** A season pack: `S02` with no episode. */
    private const SEASON_PACK = '/(?<![A-Za-z0-9])S(\d{1,2})(?![A-Za-z0-9])/i';

    private const BATCH_WORD = '/(?<![A-Za-z0-9])Batch(?![A-Za-z0-9])/i';

    /**
     * A season written at the end of the name: `Season 2`, `2nd Season`, `S2`, or
     * an uppercase Roman `II`–`X` (as TitleNormalizer reads them, §11). Only these
     * explicit markers count; a bare trailing number never does ("Mob Psycho 100").
     *
     * @var array<int, string>
     */
    private const NAME_SEASON = [
        '/^(?<name>.*\S)\s+Season\s+(?<season>\d{1,2})$/iu',
        '/^(?<name>.*\S)\s+(?<season>\d{1,2})(?:st|nd|rd|th)\s+Season$/iu',
        '/^(?<name>.*\S)\s+S(?<season>\d{1,2})$/u',
    ];

    private const ROMAN_SEASONS = ['II' => 2, 'III' => 3, 'IV' => 4, 'V' => 5, 'VI' => 6, 'VII' => 7, 'VIII' => 8, 'IX' => 9, 'X' => 10];

    /** Words that describe the file, not the show: dropped from alt titles. */
    private const TECHNICAL_WORD = '/^(?:dual[-\s]?audio|multi[-\s]?subs?|multisubs?|multiple|subtitles?|subs?|softsubs?|eng(?:lish)?|dub(?:bed)?|weekly|batch|complete|uncensored|uncut|bd|bdrip|blu-?ray|web|web-?dl|web-?rip|hevc|avc|x26[45]|h\.?26[45]|av1|aac(?:2\.0)?|e?ac-?3|ddp?\d(?:\.\d)?|flac|opus|\d{1,2}-?bits?|hdr|sdr|amzn|cr|nf|dsnp|hidive|adn|\d{3,4}p|[0-9a-f]{8}|dual|audio|multi)$/i';

    public function __construct(private readonly SubsPleaseTitleParser $subsPlease = new SubsPleaseTitleParser) {}

    public function parse(string $title): ParsedRelease
    {
        $title = trim($title);

        if ($title === '') {
            return ParsedRelease::unparsed();
        }

        if (str_starts_with($title, '['.self::SUBSPLEASE.']')) {
            return $this->subsPlease($title);
        }

        $work = preg_replace(self::EXTENSION, '', $title) ?? $title;
        $group = null;
        $rest = $work;

        if (preg_match('/^\[([^\]]+)\]\s*/', $work, $match) === 1) {
            $group = trim($match[1]);
            $rest = substr($work, strlen($match[0]));
        } elseif (preg_match('/^[^\s\[\]()]+$/', $work) === 1 && str_contains($work, '.')) {
            // A dot-separated scene name: Name.S01E06.1080p.WEB-DL.H.264-GROUP
            if (preg_match('/-([A-Za-z0-9]+)$/', $rest, $match) === 1) {
                $group = $match[1];
                $rest = substr($rest, 0, -strlen($match[0]));
            }
            $rest = str_replace(['.', '_'], ' ', $rest);
        }

        $resolution = preg_match(self::RESOLUTION, $work, $match) === 1 ? strtolower($match[1]).'p' : null;
        $crc = preg_match_all(self::CRC, $work, $matches) > 0 ? end($matches[1]) : null;

        $marker = $this->marker($rest);

        if ($marker === null) {
            return ParsedRelease::unparsed($group);
        }

        $name = $this->cleanName(substr($rest, 0, $marker['start']));

        if ($name === '') {
            return ParsedRelease::unparsed($group);
        }

        $season = $marker['season'];

        if ($season === null) {
            // "Ao no Hako Season 2" → "Ao no Hako", season 2 (suggested show "Ao no Hako S2").
            [$name, $season] = $this->seasonFromName($name);
        }

        return new ParsedRelease(
            group: $group,
            name: $name,
            season: $season,
            episode: $marker['episode'],
            version: $marker['version'],
            isBatch: $marker['isBatch'],
            batchFrom: $marker['batchFrom'],
            batchTo: $marker['batchTo'],
            resolution: $resolution,
            crc: $crc === false ? null : $crc,
            altTitles: $this->altTitles(substr($rest, $marker['end']), $name),
        );
    }

    private function subsPlease(string $title): ParsedRelease
    {
        $parsed = $this->subsPlease->parse($title, null);

        if ($parsed->name === null) {
            return ParsedRelease::unparsed(self::SUBSPLEASE);
        }

        return new ParsedRelease(
            group: self::SUBSPLEASE,
            name: $parsed->name,
            season: null,
            episode: $parsed->episode,
            version: $parsed->version,
            isBatch: $parsed->isBatch,
            batchFrom: $parsed->batchFrom,
            batchTo: $parsed->batchTo,
            resolution: $parsed->resolution,
            crc: $parsed->crc,
        );
    }

    /**
     * Where the name ends and what the episode/batch marker says. Tag blocks
     * (`[…]`, `(…)`) are blanked out first, so nothing inside them counts as a
     * marker, except SubsPlease-style `(01-12)` ranges.
     *
     * @return array{start: int, end: int, season: int|null, episode: string|null, version: int|null, isBatch: bool, batchFrom: int|null, batchTo: int|null}|null
     */
    private function marker(string $rest): ?array
    {
        $masked = preg_replace_callback('/\[[^\]]*\]|\([^)]*\)/', fn (array $m) => str_repeat(' ', strlen($m[0])), $rest) ?? $rest;

        $found = fn (array $m, array $values) => [
            'start' => $m[0][1],
            'end' => $m[0][1] + strlen($m[0][0]),
            'season' => null, 'episode' => null, 'version' => null, 'isBatch' => false, 'batchFrom' => null, 'batchTo' => null,
            ...$values,
        ];
        $group = fn (array $m, int $i) => isset($m[$i]) && $m[$i][1] >= 0 && $m[$i][0] !== '' ? $m[$i][0] : null;

        if (preg_match(self::SEASON_EPISODE, $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            $to = $group($m, 4);

            if ($to !== null) {
                return $found($m, ['season' => (int) $m[1][0], 'isBatch' => true, 'batchFrom' => (int) $m[2][0], 'batchTo' => (int) $to]);
            }

            return $found($m, ['season' => (int) $m[1][0], 'episode' => $m[2][0], 'version' => $this->version($group($m, 3))]);
        }

        if (preg_match(self::DASH_RANGE, $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $found($m, ['isBatch' => true, 'batchFrom' => (int) $m[1][0], 'batchTo' => (int) $m[2][0]]);
        }

        if (preg_match(self::DASH_EPISODE, $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $found($m, ['episode' => $m[1][0], 'version' => $this->version($group($m, 2))]);
        }

        if (preg_match(self::E_EPISODE, $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $found($m, ['episode' => $m[1][0], 'version' => $this->version($group($m, 2))]);
        }

        if (preg_match(self::PAREN_RANGE, $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $found($m, ['isBatch' => true, 'batchFrom' => (int) $m[1][0], 'batchTo' => (int) $m[2][0]]);
        }

        if (preg_match(self::SEASON_PACK, $masked, $m, PREG_OFFSET_CAPTURE) === 1) {
            return $found($m, ['season' => (int) $m[1][0], 'isBatch' => true]);
        }

        if (preg_match(self::BATCH_WORD, $rest, $m, PREG_OFFSET_CAPTURE) === 1) {
            // The name ends at the first tag block, resolution or the Batch word, whichever comes first.
            $start = $m[0][1];
            foreach (['/[\[(]/', self::RESOLUTION] as $pattern) {
                if (preg_match($pattern, $rest, $other, PREG_OFFSET_CAPTURE) === 1) {
                    $start = min($start, $other[0][1]);
                }
            }

            return ['start' => $start, 'end' => $m[0][1] + strlen($m[0][0]), 'season' => null, 'episode' => null, 'version' => null, 'isBatch' => true, 'batchFrom' => null, 'batchTo' => null];
        }

        return null;
    }

    /**
     * @return array{0: string, 1: int|null} the name without a trailing season marker, and the season
     */
    private function seasonFromName(string $name): array
    {
        foreach (self::NAME_SEASON as $pattern) {
            if (preg_match($pattern, $name, $match) === 1) {
                return $this->withoutMarker($name, $match['name'], (int) $match['season']);
            }
        }

        $roman = implode('|', array_map(fn (string $numeral) => preg_quote($numeral, '/'), array_keys(self::ROMAN_SEASONS)));

        if (preg_match('/^(?<name>.*\S)\s+(?<numeral>'.$roman.')$/u', $name, $match) === 1) {
            return $this->withoutMarker($name, $match['name'], self::ROMAN_SEASONS[$match['numeral']]);
        }

        return [$name, null];
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function withoutMarker(string $name, string $base, int $season): array
    {
        $base = trim($base, " \t-_.:~|,");

        return $base === '' || $season < 1 ? [$name, null] : [$base, $season];
    }

    private function version(?string $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/\[[^\]]*\]/', ' ', $name) ?? $name;
        $name = preg_replace('/\(\s*\d{3,4}p\s*\)/i', ' ', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name, " \t-_.:~|");
    }

    /**
     * Other names in the parenthesis blocks after the marker: technical tokens
     * (`Dual-Audio`, `Multi-Subs`, `Weekly`, …) dropped, a `Name: Alt` pair also
     * split so each side can be matched on its own. The name itself is left out.
     *
     * @return array<int, string>
     */
    private function altTitles(string $tail, string $name): array
    {
        if (preg_match_all('/\(([^)]*)\)/', $tail, $blocks) === 0) {
            return [];
        }

        $key = fn (string $value) => mb_strtolower(preg_replace('/\s+/', ' ', trim($value)) ?? $value);
        $titles = [];

        foreach ($blocks[1] as $block) {
            foreach (explode(',', $block) as $part) {
                $part = trim($part);

                if ($part === '' || $this->isTechnical($part) || preg_match('/^[\d\s~-]+$/', $part) === 1) {
                    continue;
                }

                $candidates = [$part];
                $sides = preg_split('/\s*[:：]\s*/u', $part, 2);

                if ($sides !== false && count($sides) === 2) {
                    array_push($candidates, ...$sides);
                }

                foreach ($candidates as $candidate) {
                    $candidate = trim($candidate);

                    if ($candidate !== '' && $key($candidate) !== $key($name)) {
                        $titles[$key($candidate)] ??= $candidate;
                    }
                }
            }
        }

        return array_values($titles);
    }

    private function isTechnical(string $part): bool
    {
        if (preg_match(self::TECHNICAL_WORD, $part) === 1) {
            return true;
        }

        foreach (preg_split('/\s+/', $part) ?: [] as $word) {
            if (preg_match(self::TECHNICAL_WORD, $word) !== 1) {
                return false;
            }
        }

        return true;
    }
}
