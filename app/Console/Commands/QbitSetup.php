<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\QBittorrent\QbitHealthCheck;
use App\Services\QBittorrent\QBittorrentClient;
use Illuminate\Console\Command;

class QbitSetup extends Command
{
    protected $signature = 'qbit:setup {--fix-prefs} {--self-test}';

    protected $description = 'Verify and configure the qBittorrent RSS feed, category, and preferences';

    public function handle(QBittorrentClient $client): int
    {
        $this->info('qBittorrent version: '.$client->getVersion());
        $this->info('WebAPI version: '.$client->getWebApiVersion());

        if (! $this->checkPreferences($client)) {
            return self::FAILURE;
        }

        $this->ensureFeedExists($client);
        $this->ensureCategoryExists($client);

        if ($this->option('self-test')) {
            return $this->runSelfTest($client);
        }

        return self::SUCCESS;
    }

    private function checkPreferences(QBittorrentClient $client): bool
    {
        $preferences = $client->getPreferences();

        $processingEnabled = (bool) ($preferences['rss_processing_enabled'] ?? false);
        $autoDownloadingEnabled = (bool) ($preferences['rss_auto_downloading_enabled'] ?? false);

        $this->info('RSS refresh interval: '.($preferences['rss_refresh_interval'] ?? 'unknown').' minutes');

        if ($processingEnabled && $autoDownloadingEnabled) {
            $this->info('RSS processing and auto-downloading are enabled.');

            return true;
        }

        if (! $this->option('fix-prefs')) {
            $this->warn('RSS processing enabled: '.($processingEnabled ? 'yes' : 'no'));
            $this->warn('RSS auto-downloading enabled: '.($autoDownloadingEnabled ? 'yes' : 'no'));
            $this->warn('Re-run with --fix-prefs to enable them.');

            return true;
        }

        $client->setPreferences([
            'rss_processing_enabled' => true,
            'rss_auto_downloading_enabled' => true,
        ]);

        $this->info('Enabled RSS processing and auto-downloading.');

        return true;
    }

    private function ensureFeedExists(QBittorrentClient $client): void
    {
        $feedUrl = (string) config('subtracker.feed.url');
        $feedPath = (string) config('subtracker.qbittorrent.feed_path');

        $items = $client->getRssItems();

        if (QbitHealthCheck::feedUrlPresentInTree($items, $feedUrl)) {
            $this->info('RSS feed is already present in qBittorrent.');

            return;
        }

        $client->addRssFeed($feedUrl, $feedPath);
        $this->info("Added RSS feed at path [{$feedPath}].");
    }

    private function ensureCategoryExists(QBittorrentClient $client): void
    {
        $category = (string) config('subtracker.qbittorrent.category');
        $categories = $client->getCategories();

        if (array_key_exists($category, $categories)) {
            $this->info("Category [{$category}] already exists.");

            return;
        }

        $client->createCategory($category);
        $this->info("Created category [{$category}].");
    }

    private function runSelfTest(QBittorrentClient $client): int
    {
        $ruleName = config('subtracker.qbittorrent.rule_prefix').'__selftest';
        $category = (string) config('subtracker.qbittorrent.category');

        $ruleDefinition = [
            'enabled' => false,
            'mustContain' => 'selftest',
            'mustNotContain' => '',
            'useRegex' => false,
            'episodeFilter' => '',
            'smartFilter' => false,
            'affectedFeeds' => [(string) config('subtracker.feed.url')],
            'ignoreDays' => 0,
            'assignedCategory' => $category,
        ];

        $this->info("Creating self-test rule [{$ruleName}]...");
        $client->setRssRule($ruleName, $ruleDefinition);

        $rules = $client->getRssRules();
        $rule = $rules[$ruleName] ?? null;

        if ($rule === null) {
            $this->error('The self-test rule was not found after creation.');
            $client->removeRssRule($ruleName);

            return self::FAILURE;
        }

        $this->line('Raw rule readback: '.json_encode($rule));

        $ok = true;

        if (($rule['mustContain'] ?? null) !== $ruleDefinition['mustContain']) {
            $this->error('mustContain did not round-trip as expected.');
            $ok = false;
        }

        if (($rule['useRegex'] ?? null) !== $ruleDefinition['useRegex']) {
            $this->error('useRegex did not round-trip as expected.');
            $ok = false;
        }

        if (($rule['affectedFeeds'] ?? null) !== $ruleDefinition['affectedFeeds']) {
            $this->error('affectedFeeds did not round-trip as expected.');
            $ok = false;
        }

        $appliedCategory = $rule['assignedCategory']
            ?? $rule['torrentParams']['category']
            ?? null;

        if ($appliedCategory !== $category) {
            $this->error("The category did not take effect. Expected [{$category}], got: ".json_encode($appliedCategory));
            $this->error('Not guessing a fix — see the raw rule readback above.');
            $ok = false;
        }

        $matches = $client->getMatchingArticles($ruleName);
        $this->info('Matching articles: '.count($matches));

        $client->removeRssRule($ruleName);
        $this->info("Removed self-test rule [{$ruleName}].");

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
