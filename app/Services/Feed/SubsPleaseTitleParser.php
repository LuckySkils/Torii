<?php

declare(strict_types=1);

namespace App\Services\Feed;

final class SubsPleaseTitleParser
{
    private const BATCH_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+\((?<range>[^()]*)\)\s+\((?<resolution>\d+p)\)\s+\[Batch\]$/';

    private const BATCH_RANGE_PATTERN = '/^(?<from>\d+)-(?<to>\d+)$/';

    private const EPISODE_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+-\s+(?<episode>\d+(?:\.\d+)?)(?:v(?<version>\d+))?\s+\((?<resolution>\d+p)\)\s+\[(?<crc>[0-9A-Fa-f]+)\]\.\w+$/';

    private const NO_EPISODE_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+\((?<resolution>\d+p)\)\s+\[(?<crc>[0-9A-Fa-f]+)\]\.\w+$/';

    private const CATEGORY_SUFFIX_PATTERN = '/\s+-\s+1080$/';

    public function parse(string $title, ?string $category): ParsedItem
    {
        if (preg_match(self::BATCH_PATTERN, $title, $matches) === 1) {
            [$batchFrom, $batchTo] = $this->parseBatchRange($matches['range']);

            return new ParsedItem(
                name: $this->resolveName($matches['name'], $category),
                episode: null,
                version: null,
                isBatch: true,
                batchFrom: $batchFrom,
                batchTo: $batchTo,
                resolution: $matches['resolution'],
                crc: null,
            );
        }

        if (preg_match(self::EPISODE_PATTERN, $title, $matches) === 1) {
            return new ParsedItem(
                name: $this->resolveName($matches['name'], $category),
                episode: $matches['episode'],
                version: $matches['version'] !== '' ? (int) $matches['version'] : null,
                isBatch: false,
                batchFrom: null,
                batchTo: null,
                resolution: $matches['resolution'],
                crc: $matches['crc'],
            );
        }

        if (preg_match(self::NO_EPISODE_PATTERN, $title, $matches) === 1) {
            return new ParsedItem(
                name: $this->resolveName($matches['name'], $category),
                episode: null,
                version: null,
                isBatch: false,
                batchFrom: null,
                batchTo: null,
                resolution: $matches['resolution'],
                crc: $matches['crc'],
            );
        }

        return new ParsedItem(
            name: null,
            episode: null,
            version: null,
            isBatch: false,
            batchFrom: null,
            batchTo: null,
            resolution: null,
            crc: null,
        );
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function parseBatchRange(string $range): array
    {
        if (preg_match(self::BATCH_RANGE_PATTERN, $range, $matches) === 1) {
            return [(int) $matches['from'], (int) $matches['to']];
        }

        return [null, null];
    }

    private function resolveName(string $nameFromTitle, ?string $category): string
    {
        if ($category === null || $category === '') {
            return $nameFromTitle;
        }

        return preg_replace(self::CATEGORY_SUFFIX_PATTERN, '', $category) ?? $nameFromTitle;
    }
}
