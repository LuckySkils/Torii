<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Enums\DispatchStatus;
use App\Enums\PremiereSource;
use App\Enums\ReleaseSource;
use App\Events\ShowDiscovered;
use App\Jobs\QueueReleases;
use App\Models\Release;
use App\Models\Show;
use App\Services\Premiere\PremiereCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirms a Nyaa import preview (§16): the chosen items become releases of the
 * show their title names (the existing show with that exact name, or a new
 * untracked one, which then gets matching and a poster like any discovered
 * show), sourced `nyaa`, with the magnet as their link. Then they go to
 * qBittorrent through QueueReleases, so its duplicate check, throttling and
 * error handling apply. Never creates a rule, never tracks a show, and doesn't
 * fire NewReleaseDetected (these aren't new episodes: no notifications, no
 * auto-queueing).
 */
final class NyaaImporter
{
    public function __construct(
        private readonly NyaaImportPreviews $previews,
        private readonly PremiereCalculator $premieres,
    ) {}

    /**
     * @param  array<int, string>  $keys
     * @return array{queued: int, skipped: array<int, array{key: string, title: string, reason: string}>, errors: array<int, array{key: string, title: string, message: string}>, shows: array<int, array{id: int, name: string, created: bool}>}
     *
     * @throws ValidationException for an expired preview or keys it doesn't hold
     */
    public function confirm(string $previewId, array $keys): array
    {
        $preview = $this->previews->find($previewId);

        if ($preview === null) {
            throw ValidationException::withMessages([
                'previewId' => 'This preview has expired or was already used (previews last '.config('subtracker.nyaa.preview_ttl_minutes').' minutes). Preview the link again.',
            ]);
        }

        $items = collect($preview['items'])->keyBy('key');
        $unknown = array_values(array_diff($keys, $items->keys()->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['keys' => 'Not in this preview: '.implode(', ', $unknown).'.']);
        }

        // Used once: a second confirm can't add the same items again.
        $this->previews->forget($previewId);

        $releaseIds = $skipped = $errors = [];
        $shows = [];

        foreach (array_unique($keys) as $key) {
            $item = $items[$key];

            try {
                $result = $this->release($item, $shows);
            } catch (Throwable $e) {
                logger()->error('Nyaa import: could not store an item', ['title' => $item['title'], 'error' => $e->getMessage()]);
                $errors[] = ['key' => $key, 'title' => $item['title'], 'message' => 'Could not be stored: '.$e->getMessage()];

                continue;
            }

            if (is_string($result)) {
                $skipped[] = ['key' => $key, 'title' => $item['title'], 'reason' => $result];
            } else {
                $releaseIds[] = $result->id;
            }
        }

        foreach ($shows as $entry) {
            if ($entry['created']) {
                ShowDiscovered::dispatch($entry['show']);
            }

            // Like the feed ingestor: a new release can move the "earliest seen" premiere,
            // unless SubsPlease's own data has set it.
            if ($entry['show']->premiere_source !== PremiereSource::SubsPlease) {
                $this->premieres->apply($entry['show'], $this->premieres->fromEpisode1OrEarliest($entry['show']));
            }
        }

        if ($releaseIds !== []) {
            QueueReleases::dispatch($releaseIds);
        }

        logger()->info('Nyaa import confirmed', ['queued' => count($releaseIds), 'skipped' => count($skipped), 'errors' => count($errors)]);

        return [
            'queued' => count($releaseIds),
            'skipped' => $skipped,
            'errors' => $errors,
            'shows' => array_values(array_map(fn (array $entry) => [
                'id' => $entry['show']->id,
                'name' => $entry['show']->name,
                'created' => $entry['created'],
            ], $shows)),
        ];
    }

    /**
     * The release to queue for one item, or why it was skipped. Reuses Torii's
     * release with the same infohash or view URL (a SubsPlease feed release of
     * the same torrent, or an earlier import) rather than adding a duplicate.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, array{show: Show, created: bool}>  $shows  by name, filled as shows are found or created
     */
    private function release(array $item, array &$shows): Release|string
    {
        $existing = Release::query()
            ->when($item['infohash'] !== null, fn ($q) => $q->where('infohash', $item['infohash']), fn ($q) => $q->where('guid', $item['viewUrl']))
            ->orWhere('guid', $item['viewUrl'])
            ->first();

        if ($existing !== null) {
            if ($existing->downloaded_at !== null) {
                return 'Torii already downloaded this release.';
            }

            if (in_array($existing->dispatch_status, [DispatchStatus::Sent, DispatchStatus::Exists], true)) {
                return 'Torii already sent this release to qBittorrent.';
            }

            return $existing;
        }

        $show = null;

        if ($item['showName'] !== null) {
            $shows[$item['showName']] ??= $this->show($item['showName']);
            $show = $shows[$item['showName']]['show'];
        }

        $publishedAt = $item['publishedAt'] !== null ? CarbonImmutable::parse($item['publishedAt']) : now();

        $release = Release::create([
            'show_id' => $show?->id,
            'guid' => $item['viewUrl'] !== '' ? $item['viewUrl'] : 'nyaa:'.($item['infohash'] ?? Str::uuid()),
            'title' => $item['title'],
            'episode' => $item['episode'],
            'version' => $item['version'],
            'is_batch' => $item['isBatch'],
            'batch_from' => $item['batchFrom'],
            'batch_to' => $item['batchTo'],
            'resolution' => $item['resolution'] ?? '',
            'crc' => $item['crc'],
            // The magnet when there's an infohash; otherwise only the .torrent URL can be used.
            'link' => $item['magnet'] ?? $item['torrentUrl'],
            'torrent_url' => $item['torrentUrl'],
            'infohash' => $item['infohash'],
            'size_label' => $item['size'],
            'published_at' => $publishedAt,
            'published_at_raw' => $item['publishedAtRaw'] ?? null,
            'first_seen_at' => now(),
            'source' => ReleaseSource::Nyaa,
        ]);

        if ($shows[$item['showName'] ?? '']['created'] ?? false) {
            $this->bumpLatestEpisode($show, $item);
        }

        return $release;
    }

    /**
     * The existing show with exactly this SubsPlease name, or a new untracked one.
     *
     * @return array{show: Show, created: bool}
     */
    private function show(string $name): array
    {
        $show = Show::where('name', $name)->first();

        if ($show !== null) {
            return ['show' => $show, 'created' => false];
        }

        $slug = Str::slug($name);
        if (Show::where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        return ['show' => Show::create([
            'name' => $name,
            'slug' => $slug,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]), 'created' => true];
    }

    /**
     * A show created by the import shows its highest imported episode, as the
     * feed would have set it.
     *
     * @param  array<string, mixed>  $item
     */
    private function bumpLatestEpisode(Show $show, array $item): void
    {
        $episode = $item['isBatch'] ? $item['batchTo'] : $item['episode'];

        if ($episode !== null && is_numeric($episode) && (! is_numeric($show->latest_episode) || (float) $episode > (float) $show->latest_episode)) {
            DB::table('shows')->where('id', $show->id)->update(['latest_episode' => (string) $episode]);
            $show->latest_episode = (string) $episode;
        }
    }
}
