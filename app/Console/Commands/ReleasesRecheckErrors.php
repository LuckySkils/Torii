<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DispatchStatus;
use App\Models\Release;
use App\Services\Feed\Infohash;
use App\Services\QBittorrent\QBittorrentClient;
use Illuminate\Console\Command;

class ReleasesRecheckErrors extends Command
{
    protected $signature = 'releases:recheck-errors';

    protected $description = 'Re-check qBittorrent for releases marked as a dispatch error and fix any it actually already has';

    public function handle(QBittorrentClient $client): int
    {
        $releases = Release::where('dispatch_status', DispatchStatus::Error)
            ->whereNotNull('infohash')
            ->get();

        $fixed = 0;

        foreach ($releases->chunk(50) as $chunk) {
            $hashes = $chunk->map(fn (Release $release) => Infohash::normalize($release->infohash))
                ->filter()
                ->values()
                ->all();

            if ($hashes === []) {
                continue;
            }

            $found = [];

            foreach ($client->getTorrentsInfo($hashes) as $torrent) {
                if (isset($torrent['hash'])) {
                    $found[] = strtolower($torrent['hash']);
                }
            }

            foreach ($chunk as $release) {
                $hash = Infohash::normalize($release->infohash);

                if ($hash !== null && in_array($hash, $found, true)) {
                    $release->update([
                        'dispatch_status' => DispatchStatus::Exists,
                        'dispatched_at' => now(),
                        'dispatch_error' => null,
                    ]);

                    $fixed++;
                }
            }
        }

        $this->info("Checked {$releases->count()} error releases, fixed {$fixed}.");

        return self::SUCCESS;
    }
}
