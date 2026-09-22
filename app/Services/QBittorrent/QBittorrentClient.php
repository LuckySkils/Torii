<?php

declare(strict_types=1);

namespace App\Services\QBittorrent;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class QBittorrentClient
{
    private const SID_CACHE_KEY = 'qbittorrent:sid';

    private readonly string $baseUrl;

    private readonly string $username;

    private readonly string $password;

    public function __construct()
    {
        $this->baseUrl = rtrim((string) config('subtracker.qbittorrent.url'), '/');
        $this->username = (string) config('subtracker.qbittorrent.username');
        $this->password = (string) config('subtracker.qbittorrent.password');
    }

    public function getVersion(): string
    {
        return $this->request('get', '/api/v2/app/version')->body();
    }

    public function getWebApiVersion(): string
    {
        return $this->request('get', '/api/v2/app/webapiVersion')->body();
    }

    public function getPreferences(): array
    {
        return $this->request('get', '/api/v2/app/preferences')->json();
    }

    public function setPreferences(array $preferences): void
    {
        $this->request('post', '/api/v2/app/setPreferences', [
            'json' => json_encode($preferences),
        ]);
    }

    public function getRssItems(): array
    {
        return $this->request('get', '/api/v2/rss/items', ['withData' => 'false'])->json();
    }

    public function addRssFeed(string $url, string $path): void
    {
        $this->request('post', '/api/v2/rss/addFeed', [
            'url' => $url,
            'path' => $path,
        ]);
    }

    public function refreshRssItem(string $itemPath): void
    {
        $this->request('post', '/api/v2/rss/refreshItem', [
            'itemPath' => $itemPath,
        ]);
    }

    public function getRssRules(): array
    {
        return $this->request('get', '/api/v2/rss/rules')->json();
    }

    public function setRssRule(string $ruleName, array $ruleDefinition): void
    {
        $this->request('post', '/api/v2/rss/setRule', [
            'ruleName' => $ruleName,
            'ruleDef' => json_encode($ruleDefinition),
        ]);
    }

    public function removeRssRule(string $ruleName): void
    {
        $this->request('post', '/api/v2/rss/removeRule', [
            'ruleName' => $ruleName,
        ]);
    }

    public function getMatchingArticles(string $ruleName): array
    {
        return $this->request('get', '/api/v2/rss/matchingArticles', ['ruleName' => $ruleName])->json();
    }

    public function getCategories(): array
    {
        return $this->request('get', '/api/v2/torrents/categories')->json();
    }

    public function createCategory(string $category): void
    {
        $this->request('post', '/api/v2/torrents/createCategory', [
            'category' => $category,
        ]);
    }

    /**
     * @param  array<int, string>  $hashes
     */
    public function getTorrentsInfo(array $hashes): array
    {
        return $this->request('get', '/api/v2/torrents/info', ['hashes' => implode('|', $hashes)])->json();
    }

    public function getCompletedTorrents(string $category): array
    {
        return $this->request('get', '/api/v2/torrents/info', [
            'category' => $category,
            'filter' => 'completed',
        ])->json();
    }

    public function addTorrent(string $url, string $category, string $tags): void
    {
        $this->request('post', '/api/v2/torrents/add', [
            'urls' => $url,
            'category' => $category,
            'tags' => $tags,
        ]);
    }

    private function usesAuthBypass(): bool
    {
        return $this->username === '' && $this->password === '';
    }

    private function request(string $method, string $endpoint, array $params = [], bool $isRetry = false): Response
    {
        if (! $this->usesAuthBypass()) {
            $this->ensureAuthenticated();
        }

        $http = $this->newRequest();

        $response = $method === 'get'
            ? $http->get($this->baseUrl.$endpoint, $params)
            : $http->asForm()->post($this->baseUrl.$endpoint, $params);

        if ($response->status() === 403 && ! $isRetry && ! $this->usesAuthBypass()) {
            Cache::forget(self::SID_CACHE_KEY);
            $this->login();

            return $this->request($method, $endpoint, $params, isRetry: true);
        }

        if (! $response->successful() || trim($response->body()) === 'Fails.') {
            throw new QBittorrentException($endpoint, $response->body(), $response->status());
        }

        return $response;
    }

    private function newRequest(): PendingRequest
    {
        $http = Http::withHeaders([
            'Referer' => $this->baseUrl,
        ]);

        $sid = Cache::get(self::SID_CACHE_KEY);

        if ($sid !== null) {
            $http = $http->withHeaders(['Cookie' => "SID={$sid}"]);
        }

        return $http;
    }

    private function ensureAuthenticated(): void
    {
        if (Cache::has(self::SID_CACHE_KEY)) {
            return;
        }

        $this->login();
    }

    private function login(): void
    {
        $response = Http::withHeaders(['Referer' => $this->baseUrl])
            ->asForm()
            ->post($this->baseUrl.'/api/v2/auth/login', [
                'username' => $this->username,
                'password' => $this->password,
            ]);

        if ($response->status() === 403) {
            throw new QBittorrentException('/api/v2/auth/login', $response->body(), 403);
        }

        if (! $response->successful() || trim($response->body()) !== 'Ok.') {
            throw new QBittorrentException('/api/v2/auth/login', $response->body(), $response->status());
        }

        $sid = $this->extractSidCookie($response);

        if ($sid !== null) {
            Cache::put(self::SID_CACHE_KEY, $sid, now()->addMinutes(50));
        }
    }

    private function extractSidCookie(Response $response): ?string
    {
        foreach ($response->headers()['Set-Cookie'] ?? [] as $setCookie) {
            if (preg_match('/^SID=([^;]+)/', $setCookie, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
