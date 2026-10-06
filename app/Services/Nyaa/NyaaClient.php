<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * Fetches one Nyaa RSS page. Redirects are followed by hand, a few at most, and
 * only to URLs that pass NyaaUrl::check: never off nyaa.si. The body is capped
 * (Content-Length up front, bytes received while streaming, length at the end).
 */
final class NyaaClient
{
    private const MAX_REDIRECTS = 3;

    private const TOO_LARGE = 'nyaa-body-too-large';

    /**
     * @throws NyaaException with a message fit for the user
     */
    public function fetch(string $url): string
    {
        $url = NyaaUrl::check($url);
        $maxBytes = (int) config('subtracker.nyaa.max_bytes');

        for ($hop = 0; ; $hop++) {
            try {
                $response = Http::withHeaders(['User-Agent' => 'Torii-Subtracker/1.0', 'Accept' => 'application/rss+xml, application/xml, text/xml'])
                    ->timeout((int) config('subtracker.nyaa.timeout_seconds'))
                    ->withOptions([
                        'allow_redirects' => false,
                        'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                            if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                                throw new RuntimeException(self::TOO_LARGE);
                            }
                        },
                        'progress' => function ($downloadTotal, $downloaded) use ($maxBytes): void {
                            if ($downloaded > $maxBytes) {
                                throw new RuntimeException(self::TOO_LARGE);
                            }
                        },
                    ])
                    ->get($url);
            } catch (ConnectionException|TransferException|RuntimeException $e) {
                // Guzzle wraps what on_headers/progress throw; look down the chain.
                for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                    if ($cause->getMessage() === self::TOO_LARGE) {
                        throw new NyaaException('Nyaa sent more than '.intdiv($maxBytes, 1024 * 1024).' MB; that is not an RSS page.');
                    }
                }

                throw new NyaaException('Nyaa could not be reached: '.$e->getMessage());
            }

            if ($response->redirect()) {
                if ($hop >= self::MAX_REDIRECTS) {
                    throw new NyaaException('Nyaa redirected too many times.');
                }

                $location = $response->header('Location');

                try {
                    $url = NyaaUrl::check(str_starts_with($location, '/') ? 'https://'.NyaaUrl::HOST.$location : $location);
                } catch (NyaaException) {
                    throw new NyaaException('Nyaa redirected somewhere other than a nyaa.si RSS feed, so the link was not followed.');
                }

                continue;
            }

            if (! $response->successful()) {
                throw new NyaaException("Nyaa answered with HTTP {$response->status()}.");
            }

            $body = $response->body();

            if (strlen($body) > $maxBytes) {
                throw new NyaaException('Nyaa sent more than '.intdiv($maxBytes, 1024 * 1024).' MB; that is not an RSS page.');
            }

            return $body;
        }
    }
}
