<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

use App\Contracts\DownloadDriver;
use App\Models\Release;
use App\Models\Show;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class RulesDriver implements DownloadDriver
{
    private const REFRESH_DEBOUNCE_KEY = 'qbittorrent:refresh-feed-item';

    private const REFRESH_DEBOUNCE_SECONDS = 60;

    private const MANAGED_FIELDS = [
        'mustContain',
        'mustNotContain',
        'useRegex',
        'episodeFilter',
        'smartFilter',
        'affectedFeeds',
        'ignoreDays',
    ];

    public function __construct(
        private readonly QBittorrentClient $client,
        private readonly RuleDefinitionBuilder $builder,
    ) {}

    public function track(Show $show): void
    {
        $this->readMergeWrite($this->ruleName($show), $this->builder->build($show->name));
    }

    public function untrack(Show $show): void
    {
        $ruleName = $this->ruleName($show);
        $existing = $this->client->getRssRules()[$ruleName] ?? null;

        if ($existing === null) {
            return;
        }

        $existing['enabled'] = false;
        $this->client->setRssRule($ruleName, $existing);
    }

    public function deleteRule(Show $show): void
    {
        $this->client->removeRssRule($this->ruleName($show));
    }

    public function matchingArticles(Show $show): array
    {
        return $this->client->getMatchingArticles($this->ruleName($show));
    }

    public function onNewRelease(Release $release): void
    {
        $show = $release->show;

        if ($show === null || ! $show->is_tracked) {
            return;
        }

        if (! Cache::lock(self::REFRESH_DEBOUNCE_KEY, self::REFRESH_DEBOUNCE_SECONDS)->get()) {
            return;
        }

        $this->client->refreshRssItem((string) config('subtracker.qbittorrent.feed_path'));
    }

    public function reconcile(): ReconcileReport
    {
        $prefix = (string) config('subtracker.qbittorrent.rule_prefix');
        $prefixedRules = array_filter(
            $this->client->getRssRules(),
            fn (string $name) => str_starts_with($name, $prefix),
            ARRAY_FILTER_USE_KEY,
        );

        $created = [];
        $updated = [];
        $disabled = [];
        $orphaned = [];

        foreach (Show::where('is_tracked', true)->get() as $show) {
            $ruleName = $this->ruleName($show);
            $expected = $this->builder->build($show->name);
            $existing = $prefixedRules[$ruleName] ?? null;

            if ($existing === null) {
                $this->readMergeWrite($ruleName, $expected);
                $created[] = $ruleName;

                continue;
            }

            if ($this->ruleNeedsUpdate($existing, $expected)) {
                $this->readMergeWrite($ruleName, $expected);
                $updated[] = $ruleName;
            }
        }

        foreach ($prefixedRules as $ruleName => $rule) {
            $show = Show::where('name', Str::after($ruleName, $prefix))->first();

            if ($show === null) {
                $orphaned[] = $ruleName;

                continue;
            }

            if ($show->is_tracked) {
                continue;
            }

            if (($rule['enabled'] ?? false) === true) {
                $rule['enabled'] = false;
                $this->client->setRssRule($ruleName, $rule);
                $disabled[] = $ruleName;
            }
        }

        return new ReconcileReport(
            created: $created,
            updated: $updated,
            disabled: $disabled,
            orphaned: $orphaned,
        );
    }

    private function ruleName(Show $show): string
    {
        return config('subtracker.qbittorrent.rule_prefix').$show->name;
    }

    private function readMergeWrite(string $ruleName, array $overlayFields): void
    {
        $existing = $this->client->getRssRules()[$ruleName] ?? null;

        $merged = $overlayFields;
        $merged['previouslyMatchedEpisodes'] = $existing['previouslyMatchedEpisodes'] ?? [];
        $merged['lastMatch'] = $existing['lastMatch'] ?? '';

        $this->client->setRssRule($ruleName, $merged);
    }

    private function ruleNeedsUpdate(array $existing, array $expected): bool
    {
        if (($existing['enabled'] ?? false) !== true) {
            return true;
        }

        foreach (self::MANAGED_FIELDS as $field) {
            if (($existing[$field] ?? null) !== $expected[$field]) {
                return true;
            }
        }

        return $this->currentCategory($existing) !== $expected['assignedCategory'];
    }

    private function currentCategory(array $rule): ?string
    {
        return $rule['assignedCategory'] ?? $rule['torrentParams']['category'] ?? null;
    }
}
