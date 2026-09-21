<?php

declare(strict_types=1);

namespace App\Services\Feed;

final class SubsPleaseTitleParser
{
    private const BATCH_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+\(\d+-\d+\)\s+\((?<resolution>\d+p)\)\s+\[Batch\]$/';

    private const EPISODE_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+-\s+(?<episode>\d+(?:\.\d+)?)(?:v(?<version>\d+))?\s+\((?<resolution>\d+p)\)\s+\[(?<crc>[0-9A-Fa-f]+)\]\.\w+$/';

    private const NO_EPISODE_PATTERN = '/^\[SubsPlease\]\s+(?<name>.+)\s+\((?<resolution>\d+p)\)\s+\[(?<crc>[0-9A-Fa-f]+)\]\.\w+$/';

    private const CATEGORY_SUFFIX_PATTERN = '/\s+-\s+1080$/';

    public function parse(string $title, ?string $category): ParsedItem
    {
        if (preg_match(self::BATCH_PATTERN, $title, $matches) === 1) {
            return new ParsedItem(
                name: $this->resolveName($matches['name'], $category),
                episode: null,
                version: null,
                isBatch: true,
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
                resolution: $matches['resolution'],
                crc: $matches['crc'],
            );
        }

        return new ParsedItem(
            name: null,
            episode: null,
            version: null,
            isBatch: false,
            resolution: null,
            crc: null,
        );
    }

    private function resolveName(string $nameFromTitle, ?string $category): string
    {
        if ($category === null || $category === '') {
            return $nameFromTitle;
        }

        return preg_replace(self::CATEGORY_SUFFIX_PATTERN, '', $category) ?? $nameFromTitle;
    }
}
