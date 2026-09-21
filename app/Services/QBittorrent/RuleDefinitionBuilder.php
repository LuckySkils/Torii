<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

final class RuleDefinitionBuilder
{
    private const MUST_NOT_CONTAIN = '\[Batch\]';

    /**
     * @return array<string, mixed>
     */
    public function build(string $showName): array
    {
        return [
            'enabled' => true,
            'mustContain' => $this->buildMustContain($showName),
            'mustNotContain' => self::MUST_NOT_CONTAIN,
            'useRegex' => true,
            'episodeFilter' => '',
            'smartFilter' => true,
            'affectedFeeds' => [(string) config('subtracker.feed.url')],
            'ignoreDays' => 0,
            'assignedCategory' => (string) config('subtracker.qbittorrent.category'),
        ];
    }

    public function buildMustContain(string $showName): string
    {
        return '^\[SubsPlease\] '.preg_quote($showName).' - \d';
    }

    public function buildMustNotContain(): string
    {
        return self::MUST_NOT_CONTAIN;
    }
}
