<?php

declare(strict_types=1);

namespace App\Services\Metadata\AniList;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin GraphQL transport for AniList: one endpoint, POST JSON {query, variables}.
 * Every attempt goes through the shared AniListThrottle.
 *
 * AniList's 429 comes from Cloudflare as an HTML page, so any body that isn't
 * JSON is treated as "rate limited", never as a malformed response.
 */
final class AniListClient
{
    private const MAX_RETRIES = 3;

    private const DEFAULT_BACKOFF_SECONDS = 60;

    private int $maxRetries = self::MAX_RETRIES;

    private ?float $maxWaitSeconds;

    public function __construct(private readonly AniListThrottle $throttle)
    {
        $this->maxWaitSeconds = (float) config('subtracker.metadata.anilist.max_wait_seconds');
    }

    /**
     * A copy for interactive callers (a web request): no retries, and it gives up
     * rather than wait more than a couple of seconds for a throttle slot.
     */
    public function interactive(): self
    {
        $client = clone $this;
        $client->maxRetries = 0;
        $client->maxWaitSeconds = 2.0;

        return $client;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> the response's `data` object
     *
     * @throws AniListException
     */
    public function query(string $query, array $variables = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $this->throttle->acquire($this->maxWaitSeconds);

            $response = Http::withHeaders(['User-Agent' => 'Torii-Subtracker/1.0'])
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post((string) config('subtracker.metadata.anilist.url'), [
                    'query' => $query,
                    'variables' => (object) $variables,
                ]);

            $body = json_decode($response->body(), true);

            if ($response->status() === 429 || ! is_array($body)) {
                $wait = $this->backoffSeconds($response);
                $this->throttle->blockFor($wait);

                logger()->warning('AniList rate limited', [
                    'status' => $response->status(),
                    'json' => is_array($body),
                    'retry_after' => $wait,
                    'attempt' => $attempt + 1,
                ]);

                if ($attempt >= $this->maxRetries) {
                    throw new AniListException(
                        "AniList rate limited (status {$response->status()}); retry in {$wait}s.",
                        rateLimited: true,
                        status: $response->status(),
                        retryAfter: $wait,
                    );
                }

                // The next acquire() waits out the block (or throws if that's too long).
                continue;
            }

            logger()->info('AniList request', [
                'status' => $response->status(),
                'remaining' => $response->header('X-RateLimit-Remaining'),
            ]);

            if (isset($body['errors']) && is_array($body['errors'])) {
                $messages = array_map(
                    fn ($error) => is_array($error) ? (string) ($error['message'] ?? 'unknown error') : (string) $error,
                    $body['errors'],
                );

                throw new AniListException(
                    'AniList returned errors: '.implode('; ', $messages),
                    status: $response->status(),
                );
            }

            if (! $response->successful()) {
                throw new AniListException("AniList returned status {$response->status()}.", status: $response->status());
            }

            return is_array($body['data'] ?? null) ? $body['data'] : [];
        }
    }

    private function backoffSeconds(Response $response): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return $this->clamp((int) $retryAfter);
        }

        $reset = $response->header('X-RateLimit-Reset');

        if (is_numeric($reset)) {
            return $this->clamp((int) $reset - now()->getTimestamp());
        }

        return self::DEFAULT_BACKOFF_SECONDS;
    }

    private function clamp(int $seconds): int
    {
        return max(1, min($seconds, 300));
    }
}
