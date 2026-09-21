<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\DispatchStatus;
use App\Models\Release;
use App\Services\Feed\Infohash;
use App\Services\QBittorrent\QBittorrentClient;
use App\Services\QBittorrent\QBittorrentException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class QueueReleases implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [10, 30, 60, 120];

    /**
     * @param  array<int, int>  $releaseIds
     */
    public function __construct(
        public readonly array $releaseIds,
    ) {}

    public function uniqueId(): string
    {
        $sorted = $this->releaseIds;
        sort($sorted);

        return implode(',', $sorted);
    }

    public function handle(QBittorrentClient $client): void
    {
        $releases = Release::whereIn('id', $this->releaseIds)
            ->where(function ($query) {
                $query->whereNull('dispatch_status')
                    ->orWhereNotIn('dispatch_status', [DispatchStatus::Sent, DispatchStatus::Exists]);
            })
            ->get();

        if ($releases->isEmpty()) {
            return;
        }

        $withHash = $releases->filter(fn (Release $release) => $release->infohash !== null);
        $withoutHash = $releases->filter(fn (Release $release) => $release->infohash === null);

        $existingHashes = $this->fetchExistingHashes($client, $withHash);

        foreach ($withHash as $release) {
            if (in_array(strtolower($release->infohash), $existingHashes, true)) {
                $release->update([
                    'dispatch_status' => DispatchStatus::Exists,
                    'dispatched_at' => now(),
                    'dispatch_error' => null,
                ]);

                continue;
            }

            $this->addRelease($client, $release);
        }

        foreach ($withoutHash as $release) {
            $this->addRelease($client, $release);
        }
    }

    /**
     * @param  Collection<int, Release>  $withHash
     * @return array<int, string>
     */
    private function fetchExistingHashes(QBittorrentClient $client, Collection $withHash): array
    {
        $existingHashes = [];

        foreach ($withHash->chunk(50) as $chunk) {
            $hashes = $chunk->map(fn (Release $release) => Infohash::normalize($release->infohash))
                ->filter()
                ->values()
                ->all();

            if ($hashes === []) {
                continue;
            }

            foreach ($client->getTorrentsInfo($hashes) as $torrent) {
                if (isset($torrent['hash'])) {
                    $existingHashes[] = strtolower($torrent['hash']);
                }
            }
        }

        return $existingHashes;
    }

    private function addRelease(QBittorrentClient $client, Release $release): void
    {
        $lock = Cache::lock("queue-release:{$release->id}", 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $client->addTorrent(
                $release->link,
                (string) config('subtracker.qbittorrent.category'),
                (string) config('subtracker.qbittorrent.tag'),
            );

            $release->update([
                'dispatch_status' => DispatchStatus::Sent,
                'dispatched_at' => now(),
                'dispatch_error' => null,
            ]);
        } catch (QBittorrentException $e) {
            // qBit can answer `Fails.` for a torrent it already has despite the
            // pre-check above; before giving up, confirm one more time.
            if ($this->torrentAlreadyExists($client, $release)) {
                $release->update([
                    'dispatch_status' => DispatchStatus::Exists,
                    'dispatched_at' => now(),
                    'dispatch_error' => null,
                ]);
            } else {
                $release->update([
                    'dispatch_status' => DispatchStatus::Error,
                    'dispatch_error' => $e->getMessage(),
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    private function torrentAlreadyExists(QBittorrentClient $client, Release $release): bool
    {
        $hash = Infohash::normalize($release->infohash);

        if ($hash === null) {
            return false;
        }

        foreach ($client->getTorrentsInfo([$hash]) as $torrent) {
            if (isset($torrent['hash']) && strtolower($torrent['hash']) === $hash) {
                return true;
            }
        }

        return false;
    }
}
