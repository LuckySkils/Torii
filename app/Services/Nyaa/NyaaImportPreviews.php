<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Enums\DispatchStatus;
use App\Models\Release;
use App\Models\Show;
use App\Services\Feed\SubsPleaseTitleParser;
use App\Services\QBittorrent\QBittorrentClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Builds and stores the preview of a Nyaa RSS link (§16): every item, parsed
 * with the SubsPlease title parser, given a state and a suggested selection.
 * The preview is a snapshot kept server-side for a few minutes, so confirming
 * adds exactly what was shown, never a re-fetch.
 *
 * States, in order of precedence: `unparsed` (not a SubsPlease title),
 * `in_qbit` (qBittorrent already has the infohash), `known_release` (Torii has
 * already sent or downloaded it), `superseded` (a higher version of the same
 * episode is in the list), otherwise `new`. Remakes and episodes covered by a
 * batch in the list stay `new` but unselected, with the reason.
 */
final class NyaaImportPreviews
{
    private const CACHE_PREFIX = 'nyaa-import:preview:';

    public function __construct(
        private readonly NyaaClient $client,
        private readonly NyaaFeedParser $parser,
        private readonly SubsPleaseTitleParser $titles,
        private readonly QBittorrentClient $qbit,
    ) {}

    /**
     * @return array<string, mixed> the stored preview
     *
     * @throws NyaaException
     */
    public function create(string $url): array
    {
        $feed = $this->parser->parse($this->client->fetch($url));
        $warnings = [];

        $items = [];
        foreach ($feed['items'] as $index => $item) {
            $row = $this->row($item);
            $key = $row['key'] ?? 'item-'.$index;
            $row['key'] = isset($items[$key]) ? $key.'-'.$index : $key;
            $items[$row['key']] = $row;
        }

        $inQbit = $this->hashesInQbit($items, $warnings);
        $known = $this->knownReleases($items);
        $items = $this->assignStates($items, $inQbit, $known);

        $names = array_values(array_unique(array_filter(array_column($items, 'showName'))));
        if (count($names) > 1) {
            $warnings[] = 'This feed holds releases of '.count($names).' shows ('.implode(', ', array_slice($names, 0, 5)).(count($names) > 5 ? ', …' : '').'); each item is added to the show it names.';
        }

        $existingShowId = count($names) === 1 ? Show::where('name', $names[0])->value('id') : null;
        $ttl = (int) config('subtracker.nyaa.preview_ttl_minutes');

        $preview = [
            'previewId' => (string) Str::uuid(),
            'expiresAt' => now()->addMinutes($ttl)->toIso8601String(),
            'feedTitle' => $feed['title'],
            'truncated' => count($feed['items']) >= (int) config('subtracker.nyaa.full_page_items'),
            'items' => array_values($this->sorted($items)),
            'show' => count($names) === 1 ? [
                'existingShowId' => $existingShowId,
                'name' => $names[0],
                'willCreate' => $existingShowId === null,
            ] : null,
            'warnings' => $warnings,
        ];

        Cache::put(self::CACHE_PREFIX.$preview['previewId'], $preview, now()->addMinutes($ttl));

        return $preview;
    }

    /**
     * @return array<string, mixed>|null the stored preview, null when expired or unknown
     */
    public function find(string $previewId): ?array
    {
        $preview = Cache::get(self::CACHE_PREFIX.$previewId);

        return is_array($preview) ? $preview : null;
    }

    public function forget(string $previewId): void
    {
        Cache::forget(self::CACHE_PREFIX.$previewId);
    }

    /**
     * What the preview endpoint returns: the stored preview without internals.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public static function publicView(array $preview): array
    {
        return [
            ...$preview,
            'items' => array_map(fn (array $item) => array_diff_key($item, ['publishedAtRaw' => true, 'crc' => true]), $preview['items']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NyaaItem $item): array
    {
        $parsed = $this->titles->parse($item->title, null);

        return [
            'key' => $item->nyaaId,
            'title' => $item->title,
            'showName' => $parsed->name,
            'episode' => $parsed->episode,
            'version' => $parsed->version,
            'isBatch' => $parsed->isBatch,
            'batchFrom' => $parsed->batchFrom,
            'batchTo' => $parsed->batchTo,
            'resolution' => $parsed->resolution,
            'crc' => $parsed->crc,
            'infohash' => $item->infohash,
            'magnet' => $item->infohash === null ? null : MagnetLink::build($item->infohash, $item->title),
            'torrentUrl' => $item->torrentUrl,
            'viewUrl' => $item->viewUrl,
            'publishedAt' => $item->publishedAt?->toIso8601String(),
            'publishedAtRaw' => $item->publishedAtRaw,
            'size' => $item->size,
            'seeders' => $item->seeders,
            'trusted' => $item->trusted,
            'remake' => $item->remake,
            'state' => 'new',
            'selected' => true,
            'reason' => null,
        ];
    }

    /**
     * One torrents/info call per 50 hashes. If qBittorrent can't be reached the
     * preview still works; dispatch checks again before adding anything.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<int, string>  $warnings
     * @return array<string, true> infohashes qBittorrent has
     */
    private function hashesInQbit(array $items, array &$warnings): array
    {
        $hashes = array_values(array_unique(array_filter(array_column($items, 'infohash'))));
        $found = [];

        try {
            foreach (array_chunk($hashes, 50) as $chunk) {
                foreach ($this->qbit->getTorrentsInfo($chunk) as $torrent) {
                    if (isset($torrent['hash'])) {
                        $found[strtolower((string) $torrent['hash'])] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            logger()->warning('Nyaa import preview: qBittorrent check failed', ['error' => $e->getMessage()]);
            $warnings[] = 'qBittorrent could not be reached, so items it already has are not marked; it still skips them when adding.';
        }

        return $found;
    }

    /**
     * Torii's releases with the same infohash or Nyaa view URL.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, Release> by infohash and by guid
     */
    private function knownReleases(array $items): array
    {
        $hashes = array_values(array_filter(array_column($items, 'infohash')));
        $guids = array_values(array_filter(array_column($items, 'viewUrl')));

        if ($hashes === [] && $guids === []) {
            return [];
        }

        $known = [];
        Release::query()
            ->where(fn ($q) => $q->whereIn('infohash', $hashes)->orWhereIn('guid', $guids))
            ->get(['id', 'guid', 'infohash', 'dispatch_status', 'downloaded_at'])
            ->each(function (Release $release) use (&$known) {
                if ($release->infohash !== null) {
                    $known[$release->infohash] = $release;
                }
                $known[$release->guid] = $release;
            });

        return $known;
    }

    /**
     * @param  array<string, array<string, mixed>>  $items
     * @param  array<string, true>  $inQbit
     * @param  array<string, Release>  $known
     * @return array<string, array<string, mixed>>
     */
    private function assignStates(array $items, array $inQbit, array $known): array
    {
        $versionOf = fn (array $item) => $item['version'] ?? 1;

        // Highest version per show, episode and resolution (remakes count too: a v2 is a v2).
        $best = [];
        foreach ($items as $item) {
            if ($item['showName'] !== null && ! $item['isBatch'] && $item['episode'] !== null) {
                $group = $item['showName'].'|'.$item['episode'].'|'.$item['resolution'];
                $best[$group] = max($best[$group] ?? 0, $versionOf($item));
            }
        }

        // Batches per show, for the "prefer the batch" rule (as when tracking: DownloadPlanner).
        $batches = [];
        foreach ($items as $item) {
            if ($item['showName'] !== null && $item['isBatch'] && ! $item['remake']) {
                $batches[$item['showName']][] = $item;
            }
        }

        foreach ($items as $key => $item) {
            $release = $known[$item['infohash'] ?? ''] ?? $known[$item['viewUrl']] ?? null;
            $group = $item['showName'].'|'.$item['episode'].'|'.$item['resolution'];

            [$state, $reason] = match (true) {
                $item['showName'] === null => ['unparsed', "The title isn't in SubsPlease's format, so Torii can't tell its show or episode."],
                $item['infohash'] !== null && isset($inQbit[$item['infohash']]) => ['in_qbit', 'Already in qBittorrent.'],
                $release !== null && $release->downloaded_at !== null => ['known_release', 'Torii already downloaded this release.'],
                $release !== null && in_array($release->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true) => ['known_release', 'Torii already sent this release to qBittorrent.'],
                ! $item['isBatch'] && $item['episode'] !== null && $versionOf($item) < ($best[$group] ?? 0) => ['superseded', 'v'.$best[$group].' of this episode is also in the list.'],
                $item['remake'] => ['new', 'Marked as a remake on Nyaa.'],
                default => ['new', $this->coveredBy($item, $batches[$item['showName']] ?? [])],
            };

            $items[$key] = [...$item, 'state' => $state, 'selected' => $reason === null, 'reason' => $reason];
        }

        return $items;
    }

    /**
     * Why a single episode is left out in favour of a batch in the list, or null.
     * Mirrors DownloadPlanner: a batch without a parsed range covers everything,
     * otherwise episodes up to the highest batch end are covered; specials (no
     * numeric episode) never are.
     *
     * @param  array<string, mixed>  $item
     * @param  array<int, array<string, mixed>>  $batches
     */
    private function coveredBy(array $item, array $batches): ?string
    {
        if ($item['isBatch'] || $batches === [] || ($item['episode'] !== null && ! is_numeric($item['episode']))) {
            return null;
        }

        foreach ($batches as $batch) {
            if ($batch['batchFrom'] === null || $batch['batchTo'] === null) {
                return 'Covered by a batch in this list.';
            }
        }

        $episode = (float) $item['episode'];
        $highestEnd = max(array_column($batches, 'batchTo'));

        if ($item['episode'] === null || $episode > $highestEnd) {
            return null;
        }

        $covering = collect($batches)->first(fn (array $batch) => $episode >= $batch['batchFrom'] && $episode <= $batch['batchTo'])
            ?? collect($batches)->sortByDesc('batchTo')->first();

        return "Covered by the batch of episodes {$covering['batchFrom']}-{$covering['batchTo']} in this list.";
    }

    /**
     * By show (unparsed last), batches first, then by episode and version.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>>
     */
    private function sorted(array $items): array
    {
        uasort($items, fn (array $a, array $b) => [
            $a['showName'] === null, $a['showName'], ! $a['isBatch'], (float) ($a['batchFrom'] ?? $a['episode'] ?? INF), $a['version'] ?? 1,
        ] <=> [
            $b['showName'] === null, $b['showName'], ! $b['isBatch'], (float) ($b['batchFrom'] ?? $b['episode'] ?? INF), $b['version'] ?? 1,
        ]);

        return $items;
    }
}
