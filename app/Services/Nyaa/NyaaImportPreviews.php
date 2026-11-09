<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Enums\DispatchStatus;
use App\Models\Release;
use App\Services\Metadata\Matching\TitleNormalizer;
use App\Services\QBittorrent\QBittorrentClient;
use App\Services\Releases\ReleaseTitleParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Builds and stores the preview of a Nyaa RSS link (§16): every item, parsed
 * with the general release title parser, grouped into series, each series with
 * a target show (suggested, then chosen by the user) and every item with a state
 * and a suggested selection. The preview is a snapshot kept server-side for a
 * few minutes, so confirming adds exactly what was shown, never a re-fetch.
 *
 * States, in order of precedence: `unparsed`, `in_qbit` (qBittorrent already has
 * the infohash), `known_release` (Torii has already sent or downloaded it),
 * `have_episode` (the target show already has that episode from any group),
 * `superseded` (a higher version from the same group is in the list),
 * `duplicate_episode` (another copy of the same episode and resolution, from any
 * group or encode, is the one preselected), otherwise `new`. Remakes and episodes covered by a batch of the
 * series stay `new` but unselected, with the reason. States depend on the
 * target, so they're recomputed on every retarget.
 */
final class NyaaImportPreviews
{
    private const CACHE_PREFIX = 'nyaa-import:preview:';

    /** Stored with each item, never sent to the browser. */
    private const INTERNAL_ITEM_FIELDS = ['publishedAtRaw', 'crc', 'altTitles', 'inQbit', 'known', 'position'];

    public function __construct(
        private readonly NyaaClient $client,
        private readonly NyaaFeedParser $parser,
        private readonly ReleaseTitleParser $titles,
        private readonly TitleNormalizer $normalizer,
        private readonly SeriesTargets $targets,
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
            $row = [...$this->row($item), 'position' => $index];
            $key = $row['key'] ?? 'item-'.$index;
            $row['key'] = isset($items[$key]) ? $key.'-'.$index : $key;
            $items[$row['key']] = $row;
        }

        $inQbit = $this->hashesInQbit($items, $warnings);
        $known = $this->knownReleases($items);

        foreach ($items as $key => $item) {
            $release = $known[$item['infohash'] ?? ''] ?? $known[$item['viewUrl']] ?? null;
            $items[$key]['inQbit'] = $item['infohash'] !== null && isset($inQbit[$item['infohash']]);
            $items[$key]['known'] = match (true) {
                $release === null => null,
                $release->downloaded_at !== null => 'downloaded',
                in_array($release->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true) => 'sent',
                default => null,
            };
        }

        $series = $this->targets->describe($this->series($items));
        $ttl = (int) config('subtracker.nyaa.preview_ttl_minutes');

        $preview = [
            'previewId' => (string) Str::uuid(),
            'expiresAt' => now()->addMinutes($ttl)->toIso8601String(),
            'feedTitle' => $feed['title'],
            'truncated' => count($feed['items']) >= (int) config('subtracker.nyaa.full_page_items'),
            'items' => array_values($items),
            'series' => array_values($series),
            'warnings' => $warnings,
        ];

        $preview = $this->withStates($preview);

        Cache::put(self::CACHE_PREFIX.$preview['previewId'], $preview, now()->addMinutes($ttl));

        return $preview;
    }

    /**
     * Points one series at another show (an existing one, a new name, or an
     * anime: its linked show or a new show linked to it) and recomputes the
     * states. A new name that some show has exactly becomes that show.
     *
     * @return array<string, mixed> the updated stored preview
     *
     * @throws ValidationException for an unknown preview, series, show or anime, or a bad name
     */
    public function retarget(string $previewId, string $seriesKey, ?int $showId, ?string $newName, ?int $animeId = null): array
    {
        $preview = $this->find($previewId);

        if ($preview === null) {
            throw ValidationException::withMessages(['previewId' => self::expiredMessage()]);
        }

        $index = array_search($seriesKey, array_column($preview['series'], 'key'), true);

        if ($index === false) {
            throw ValidationException::withMessages(['seriesKey' => "This preview has no series \"{$seriesKey}\"."]);
        }

        $series = $preview['series'][$index];

        // An anime another series already goes to: the same show by default, not a second one.
        if ($animeId !== null && $newName === null) {
            foreach ($preview['series'] as $other) {
                if ($other['key'] !== $seriesKey && ($other['target']['anime']['id'] ?? null) === $animeId) {
                    $newName = $other['target']['kind'] === 'new' ? $other['target']['name'] : $other['target']['showName'];
                    break;
                }
            }
        }

        $preview['series'][$index]['target'] = $this->targets->chosen($showId, $newName, $series['groups'], $animeId, $series['suggestedName']);
        $preview = $this->withStates($preview);

        $expiresAt = CarbonImmutable::parse($preview['expiresAt']);

        if ($expiresAt->isFuture()) {
            Cache::put(self::CACHE_PREFIX.$previewId, $preview, $expiresAt);
        }

        return $preview;
    }

    /**
     * @return array<string, mixed>|null the stored preview, null when expired or unknown
     */
    public function find(string $previewId): ?array
    {
        $preview = Cache::get(self::CACHE_PREFIX.$previewId);

        // A preview cached before series existed can't be confirmed: it counts as expired.
        return is_array($preview) && isset($preview['series']) ? $preview : null;
    }

    public function forget(string $previewId): void
    {
        Cache::forget(self::CACHE_PREFIX.$previewId);
    }

    /**
     * Which show a target resolves to, for grouping: an existing show by id, a
     * new one by its name (trimmed, case-insensitive). Series with the same key
     * end up in one show on confirm.
     *
     * @param  array<string, mixed>  $target
     */
    public static function targetKey(array $target): string
    {
        return $target['kind'] === 'existing'
            ? 'id:'.$target['showId']
            : 'name:'.mb_strtolower(trim((string) $target['name']));
    }

    public static function expiredMessage(): string
    {
        return 'This preview has expired or was already used (previews last '.config('subtracker.nyaa.preview_ttl_minutes').' minutes). Preview the link again.';
    }

    /**
     * What the preview endpoints return: the stored preview without internals.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    public static function publicView(array $preview): array
    {
        return [
            ...$preview,
            'items' => array_map(fn (array $item) => array_diff_key($item, array_flip(self::INTERNAL_ITEM_FIELDS)), $preview['items']),
        ];
    }

    /**
     * The series key a suggested show name groups under: its normalized base and
     * season number, so "Ao Ashi S2" and "Ao Ashi 2nd Season" are one series.
     */
    public function seriesKey(string $suggestedName): string
    {
        $normalized = $this->normalizer->normalize($suggestedName);
        $base = str_replace(' ', '', $normalized->base);

        return ($base !== '' ? $base : mb_strtolower(trim($suggestedName))).'|s'.$normalized->seasonNumber();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(NyaaItem $item): array
    {
        $parsed = $this->titles->parse($item->title);
        $suggested = $parsed->suggestedShowName();

        return [
            'key' => $item->nyaaId,
            'title' => $item->title,
            'group' => $parsed->group,
            'showName' => $suggested,
            'parsedName' => $parsed->name,
            'season' => $parsed->season,
            'seriesKey' => $suggested === null ? null : $this->seriesKey($suggested),
            'episode' => $parsed->episode,
            'version' => $parsed->version,
            'isBatch' => $parsed->isBatch,
            'batchFrom' => $parsed->batchFrom,
            'batchTo' => $parsed->batchTo,
            'resolution' => $parsed->resolution,
            'infohash' => $item->infohash,
            'magnet' => $item->infohash === null ? null : MagnetLink::build($item->infohash, $item->title),
            'torrentUrl' => $item->torrentUrl,
            'viewUrl' => $item->viewUrl,
            'publishedAt' => $item->publishedAt?->toIso8601String(),
            'size' => $item->size,
            'seeders' => $item->seeders,
            'trusted' => $item->trusted,
            'remake' => $item->remake,
            'state' => 'new',
            'selected' => true,
            'reason' => null,
            'publishedAtRaw' => $item->publishedAtRaw,
            'crc' => $parsed->crc,
            'altTitles' => $parsed->altTitles,
        ];
    }

    /**
     * The parsed items grouped into series, each with its suggested target.
     * The SubsPlease item names the series when there is one (its name is
     * exactly the show's), otherwise the first item.
     *
     * @param  array<string, array<string, mixed>>  $items
     * @return array<string, array<string, mixed>> by series key, ordered by suggested name
     */
    private function series(array $items): array
    {
        $grouped = [];
        foreach ($items as $item) {
            if ($item['seriesKey'] !== null) {
                $grouped[$item['seriesKey']][] = $item;
            }
        }

        $series = [];
        foreach ($grouped as $key => $members) {
            $lead = collect($members)->first(fn (array $item) => $item['group'] === ReleaseTitleParser::SUBSPLEASE) ?? $members[0];
            $season = $lead['season'] ?? $this->normalizer->normalize($lead['showName'])->season;
            $groups = array_values(array_unique(array_filter(array_column($members, 'group'), fn ($group) => $group !== null)));
            usort($groups, fn (string $a, string $b) => [$a !== ReleaseTitleParser::SUBSPLEASE, mb_strtolower($a)] <=> [$b !== ReleaseTitleParser::SUBSPLEASE, mb_strtolower($b)]);

            $altTitles = [];
            foreach ($members as $member) {
                foreach ($member['altTitles'] as $alt) {
                    $altTitles[mb_strtolower($alt)] ??= $alt;
                }
            }
            $altTitles = array_values($altTitles);

            $series[$key] = [
                'key' => (string) $key,
                'parsedName' => $lead['parsedName'],
                'season' => $season,
                'suggestedName' => $lead['showName'],
                'altTitles' => $altTitles,
                'groups' => $groups,
                'itemKeys' => array_column($members, 'key'),
                ...$this->targets->suggest($lead['showName'], $season, $altTitles),
            ];
        }

        uasort($series, fn (array $a, array $b) => [$a['suggestedName'], $a['key']] <=> [$b['suggestedName'], $b['key']]);

        // Series whose new show would link to the same anime (Erai-raws' "Demons Crest" and ToonsHub's
        // "Devils Crest" are both the anime "Demons' Crest") share the first one's name: one show, not two.
        $nameForAnime = [];
        foreach ($series as $key => $entry) {
            $animeId = $entry['target']['kind'] === 'new' ? ($entry['target']['animeId'] ?? null) : null;

            if ($animeId !== null) {
                $nameForAnime[$animeId] ??= $entry['target']['name'];
                $series[$key]['target']['name'] = $nameForAnime[$animeId];
            }
        }

        return $series;
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
     * Every item's state, selection and reason for the series' current targets,
     * and the items in display order.
     *
     * @param  array<string, mixed>  $preview
     * @return array<string, mixed>
     */
    private function withStates(array $preview): array
    {
        $targets = array_column($preview['series'], 'target', 'key');
        $held = HeldEpisodes::forShows(array_values(array_filter(array_map(
            fn (array $target) => $target['kind'] === 'existing' ? $target['showId'] : null,
            $targets,
        ))));

        $items = [];
        foreach ($preview['items'] as $item) {
            $items[$item['key']] = $item;
        }

        $versionOf = fn (array $item) => $item['version'] ?? 1;
        $episodeOf = fn (array $item) => Release::episodeKey($item['episode']);
        $versionKey = fn (array $item) => $item['seriesKey'].'|'.$item['group'].'|'.$episodeOf($item).'|'.$item['resolution'];

        // Highest version per series, group, episode and resolution (remakes count too: a v2 is a v2).
        $best = [];
        foreach ($items as $item) {
            if ($item['seriesKey'] !== null && ! $item['isBatch'] && $item['episode'] !== null) {
                $best[$versionKey($item)] = max($best[$versionKey($item)] ?? 0, $versionOf($item));
            }
        }

        // Batches per series, for the "prefer the batch" rule (as when tracking: DownloadPlanner).
        $batches = [];
        foreach ($items as $item) {
            if ($item['seriesKey'] !== null && $item['isBatch'] && ! $item['remake']) {
                $batches[$item['seriesKey']][] = $item;
            }
        }

        $states = [];
        foreach ($items as $key => $item) {
            $target = $item['seriesKey'] === null ? null : ($targets[$item['seriesKey']] ?? null);
            $have = $target !== null && $target['kind'] === 'existing' && ! $item['isBatch']
                ? $held->reasonFor($target['showId'], $item['episode'])
                : null;

            $states[$key] = match (true) {
                $item['seriesKey'] === null => ['unparsed', "Torii couldn't read a show and episode from this title."],
                $item['inQbit'] => ['in_qbit', 'Already in qBittorrent.'],
                $item['known'] === 'downloaded' => ['known_release', 'Torii already downloaded this release.'],
                $item['known'] === 'sent' => ['known_release', 'Torii already sent this release to qBittorrent.'],
                $have !== null => ['have_episode', $have],
                ! $item['isBatch'] && $item['episode'] !== null && $versionOf($item) < ($best[$versionKey($item)] ?? 0) => ['superseded', 'v'.$best[$versionKey($item)].' of this episode is also in the list.'],
                default => null,
            };
        }

        // Several copies of one episode and resolution going to one show (other groups, possibly in
        // other series pointed at the same show, or one group's HEVC and AVC encodes): preselect
        // exactly one, deterministically (not a remake, SubsPlease,
        // trusted, most seeders, then first in the feed). Which one doesn't matter much: the user decides.
        $buckets = [];
        foreach ($items as $key => $item) {
            if ($states[$key] === null && ! $item['isBatch'] && $item['episode'] !== null) {
                $buckets[self::targetKey($targets[$item['seriesKey']]).'|'.$episodeOf($item).'|'.$item['resolution']][] = $item;
            }
        }

        foreach ($buckets as $bucket) {
            if (count($bucket) < 2) {
                continue;
            }

            usort($bucket, fn (array $a, array $b) => [
                $a['remake'], $a['group'] !== ReleaseTitleParser::SUBSPLEASE, ! $a['trusted'], -$a['seeders'], $a['position'] ?? 0,
            ] <=> [
                $b['remake'], $b['group'] !== ReleaseTitleParser::SUBSPLEASE, ! $b['trusted'], -$b['seeders'], $b['position'] ?? 0,
            ]);
            $kept = $bucket[0];

            foreach (array_slice($bucket, 1) as $item) {
                $states[$item['key']] = ['duplicate_episode', "Another copy of episode {$item['episode']} is selected".($kept['group'] !== null ? " ({$kept['group']})" : '').'.'];
            }
        }

        foreach ($items as $key => $item) {
            [$state, $reason] = $states[$key] ?? ['new', match (true) {
                $item['remake'] => 'Marked as a remake on Nyaa.',
                default => $this->coveredBy($item, $batches[$item['seriesKey']] ?? []),
            }];

            $items[$key] = [...$item, 'state' => $state, 'selected' => $reason === null, 'reason' => $reason];
        }

        $order = array_flip(array_column($preview['series'], 'key'));

        uasort($items, fn (array $a, array $b) => [
            $a['seriesKey'] === null, $order[$a['seriesKey'] ?? ''] ?? PHP_INT_MAX, ! $a['isBatch'], (float) ($a['batchFrom'] ?? $a['episode'] ?? INF),
            $a['group'] !== ReleaseTitleParser::SUBSPLEASE, (string) $a['group'], $a['version'] ?? 1,
        ] <=> [
            $b['seriesKey'] === null, $order[$b['seriesKey'] ?? ''] ?? PHP_INT_MAX, ! $b['isBatch'], (float) ($b['batchFrom'] ?? $b['episode'] ?? INF),
            $b['group'] !== ReleaseTitleParser::SUBSPLEASE, (string) $b['group'], $b['version'] ?? 1,
        ]);

        return [...$preview, 'items' => array_values($items)];
    }

    /**
     * Why a single episode is left out in favour of a batch of its series in the
     * list, or null. Mirrors DownloadPlanner: a batch without a parsed range
     * covers everything, otherwise episodes up to the highest batch end are
     * covered; specials (no numeric episode) never are.
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
}
