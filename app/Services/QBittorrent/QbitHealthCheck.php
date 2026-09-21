<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

use Illuminate\Http\Client\ConnectionException;
use Throwable;

final class QbitHealthCheck
{
    public function __construct(
        private readonly QBittorrentClient $client,
    ) {}

    public function check(): QbitHealth
    {
        try {
            $version = $this->client->getVersion();
            $webapi = $this->client->getWebApiVersion();
        } catch (ConnectionException) {
            return new QbitHealth(
                reachable: false,
                version: null,
                webapi: null,
                auth: false,
                feed: false,
                prefs: false,
                category: false,
            );
        } catch (QBittorrentException) {
            return new QbitHealth(
                reachable: true,
                version: null,
                webapi: null,
                auth: false,
                feed: false,
                prefs: false,
                category: false,
            );
        }

        return new QbitHealth(
            reachable: true,
            version: $version,
            webapi: $webapi,
            auth: true,
            feed: $this->checkFeed(),
            prefs: $this->checkPreferences(),
            category: $this->checkCategory(),
        );
    }

    private function checkPreferences(): bool
    {
        try {
            $preferences = $this->client->getPreferences();

            return (bool) ($preferences['rss_processing_enabled'] ?? false)
                && (bool) ($preferences['rss_auto_downloading_enabled'] ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    private function checkFeed(): bool
    {
        try {
            return self::feedUrlPresentInTree($this->client->getRssItems(), (string) config('subtracker.feed.url'));
        } catch (Throwable) {
            return false;
        }
    }

    private function checkCategory(): bool
    {
        try {
            return array_key_exists((string) config('subtracker.qbittorrent.category'), $this->client->getCategories());
        } catch (Throwable) {
            return false;
        }
    }

    public static function feedUrlPresentInTree(array $items, string $feedUrl): bool
    {
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            if (isset($item['url']) && $item['url'] === $feedUrl) {
                return true;
            }

            if (self::feedUrlPresentInTree($item, $feedUrl)) {
                return true;
            }
        }

        return false;
    }
}
