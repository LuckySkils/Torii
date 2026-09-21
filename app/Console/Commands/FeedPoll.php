<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\FeedPoll as FeedPollModel;
use App\Services\Feed\FeedClient;
use App\Services\Feed\FeedIngestor;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

class FeedPoll extends Command
{
    private const NEXT_POLL_CACHE_KEY = 'feed:next_poll_at';

    protected $signature = 'feed:poll {--force : Fetch immediately, ignoring the scheduled interval}';

    protected $description = 'Poll the SubsPlease RSS feed and ingest any new releases';

    public function handle(FeedClient $client, FeedIngestor $ingestor): int
    {
        if (! $this->option('force') && ! $this->isTimeToPoll()) {
            $this->info('Not time to poll yet, skipping.');

            return self::SUCCESS;
        }

        $lastPoll = FeedPollModel::query()->orderByDesc('id')->first();
        $startedAt = now();

        try {
            $result = $client->fetch($lastPoll?->etag, $lastPoll?->last_modified);
        } catch (ConnectionException $e) {
            FeedPollModel::create([
                'started_at' => $startedAt,
                'finished_at' => now(),
                'http_status' => null,
                'not_modified' => false,
                'items_total' => 0,
                'items_new' => 0,
                'shows_new' => 0,
                'error' => $e->getMessage(),
            ]);

            $this->scheduleNextPoll();
            $this->error("Feed poll failed: {$e->getMessage()}");

            return self::FAILURE;
        }

        if ($result->notModified) {
            FeedPollModel::create([
                'started_at' => $startedAt,
                'finished_at' => now(),
                'http_status' => $result->httpStatus,
                'not_modified' => true,
                'items_total' => 0,
                'items_new' => 0,
                'shows_new' => 0,
                'etag' => $result->etag ?? $lastPoll?->etag,
                'last_modified' => $result->lastModified ?? $lastPoll?->last_modified,
            ]);

            $this->scheduleNextPoll();
            $this->info('Feed not modified since last poll.');

            return self::SUCCESS;
        }

        $ingestResult = $ingestor->ingest($result->body ?? '');

        FeedPollModel::create([
            'started_at' => $startedAt,
            'finished_at' => now(),
            'http_status' => $result->httpStatus,
            'not_modified' => false,
            'items_total' => $ingestResult->itemsTotal,
            'items_new' => $ingestResult->itemsNew,
            'shows_new' => $ingestResult->showsNew,
            'etag' => $result->etag,
            'last_modified' => $result->lastModified,
        ]);

        $this->scheduleNextPoll();
        $this->info("Polled feed: {$ingestResult->itemsNew} new releases, {$ingestResult->showsNew} new shows.");

        return self::SUCCESS;
    }

    private function isTimeToPoll(): bool
    {
        $nextPollAt = Cache::get(self::NEXT_POLL_CACHE_KEY);

        return $nextPollAt === null || now()->greaterThanOrEqualTo($nextPollAt);
    }

    private function scheduleNextPoll(): void
    {
        $minutes = (int) config('subtracker.feed.poll_base_minutes');
        $nextPollAt = now()->addMinutes($minutes);

        Cache::put(self::NEXT_POLL_CACHE_KEY, $nextPollAt, $nextPollAt->addMinutes($minutes));

        logger()->info('Feed poll scheduled', ['mode' => 'base', 'next_poll_at' => $nextPollAt->toIso8601String()]);
    }
}
