<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\ReleaseDownloaded;
use App\Models\Release;
use App\Services\Feed\Infohash;
use App\Services\QBittorrent\QBittorrentClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

class QbitCheckCompleted extends Command
{
    private const INITIALIZED_CACHE_KEY = 'completion_sync_initialized';

    protected $signature = 'qbit:check-completed';

    protected $description = 'Check qBittorrent for completed torrents and record download completion on matching releases';

    public function handle(QBittorrentClient $client): int
    {
        try {
            $torrents = $client->getCompletedTorrents((string) config('subtracker.qbittorrent.category'));
        } catch (Throwable $e) {
            logger()->error("qbit:check-completed failed: {$e->getMessage()}");
            $this->error("Failed to reach qBittorrent: {$e->getMessage()}");

            return self::FAILURE;
        }

        $isFirstRun = ! Cache::has(self::INITIALIZED_CACHE_KEY);
        $matched = 0;

        foreach ($torrents as $torrent) {
            $hash = Infohash::normalize($torrent['hash'] ?? null);

            if ($hash === null) {
                continue;
            }

            $release = Release::whereRaw('lower(infohash) = ?', [$hash])
                ->whereNull('downloaded_at')
                ->first();

            if ($release === null) {
                continue;
            }

            $completionOn = (int) ($torrent['completion_on'] ?? 0);
            $downloadedAt = $completionOn > 0 ? Carbon::createFromTimestamp($completionOn) : now();

            $release->update(['downloaded_at' => $downloadedAt]);
            $matched++;

            if (! $isFirstRun && $downloadedAt->greaterThan(now()->subHours(24))) {
                ReleaseDownloaded::dispatch($release);
            }
        }

        if ($isFirstRun) {
            Cache::forever(self::INITIALIZED_CACHE_KEY, true);
        }

        $this->info("Checked completed torrents: {$matched} newly matched.");

        return self::SUCCESS;
    }
}
