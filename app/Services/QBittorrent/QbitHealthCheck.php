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
        } catch (ConnectionException $e) {
            return new QbitHealth(
                reachable: false,
                version: null,
                webapi: null,
                auth: false,
                feed: false,
                prefs: false,
                category: false,
                details: ['reachable' => $e->getMessage()],
            );
        } catch (QBittorrentException $e) {
            return new QbitHealth(
                reachable: true,
                version: null,
                webapi: null,
                auth: false,
                feed: false,
                prefs: false,
                category: false,
                details: ['auth' => $e->getMessage()],
            );
        }

        [$feed, $feedDetail] = $this->checkFeed();
        [$prefs, $prefsDetail] = $this->checkPreferences();
        [$category, $categoryDetail] = $this->checkCategory();

        return new QbitHealth(
            reachable: true,
            version: $version,
            webapi: $webapi,
            auth: true,
            feed: $feed,
            prefs: $prefs,
            category: $category,
            details: array_filter(['feed' => $feedDetail, 'prefs' => $prefsDetail, 'category' => $categoryDetail]),
        );
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function checkPreferences(): array
    {
        try {
            $preferences = $this->client->getPreferences();
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }

        $off = array_values(array_filter(
            ['rss_processing_enabled', 'rss_auto_downloading_enabled'],
            fn (string $preference) => ! (bool) ($preferences[$preference] ?? false),
        ));

        return $off === []
            ? [true, null]
            : [false, 'qBittorrent preference off: '.implode(', ', $off).'.'];
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function checkFeed(): array
    {
        $feedUrl = (string) config('subtracker.feed.url');

        try {
            return self::feedUrlPresentInTree($this->client->getRssItems(), $feedUrl)
                ? [true, null]
                : [false, "FEED_URL {$feedUrl} isn't among qBittorrent's RSS feeds."];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    private function checkCategory(): array
    {
        $category = (string) config('subtracker.qbittorrent.category');

        try {
            return array_key_exists($category, $this->client->getCategories())
                ? [true, null]
                : [false, "QBIT_CATEGORY \"{$category}\" doesn't exist in qBittorrent."];
        } catch (Throwable $e) {
            return [false, $e->getMessage()];
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
