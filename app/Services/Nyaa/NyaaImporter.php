<?php

declare(strict_types=1);

namespace App\Services\Nyaa;

use App\Enums\DispatchStatus;
use App\Enums\PremiereSource;
use App\Enums\ReleaseSource;
use App\Events\ShowDiscovered;
use App\Jobs\FetchAnimeCover;
use App\Jobs\QueueReleases;
use App\Models\Anime;
use App\Models\Release;
use App\Models\Show;
use App\Models\ShowAnimeLink;
use App\Services\Metadata\Matching\ShowAnimeLinker;
use App\Services\Premiere\PremiereCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Confirms a Nyaa import preview (§16): the chosen items become releases of
 * their series' target show (the existing show chosen in the preview, or a new
 * untracked one with the target's name, which then gets matching and a poster
 * like any discovered show), sourced `nyaa`, with the parsed group and the
 * magnet as their link. An episode the target show got meanwhile (another
 * group's, downloaded or sent) is skipped: the preview is a snapshot. Then they go to
 * qBittorrent through QueueReleases, so its duplicate check, throttling and
 * error handling apply. Never creates a rule, never tracks a show, and doesn't
 * fire NewReleaseDetected (these aren't new episodes: no notifications, no
 * auto-queueing).
 *
 * A target carrying an anime (a new show picked through its anime entry, or an
 * unlinked show of that name) gets a manual link to it, made with the show, and
 * its cover queued. A show that has a link by then keeps it; anything unusual
 * (several series of one show with different anime, an anime that got another
 * show since the preview) is reported in `warnings` and logged, never an error.
 */
final class NyaaImporter
{
    public function __construct(
        private readonly NyaaImportPreviews $previews,
        private readonly PremiereCalculator $premieres,
        private readonly ShowAnimeLinker $linker,
    ) {}

    /**
     * @param  array<int, string>  $keys
     * @return array{queued: int, skipped: array<int, array{key: string, title: string, reason: string}>, errors: array<int, array{key: string, title: string, message: string}>, shows: array<int, array{id: int, name: string, created: bool, linkedAnime: array{id: int, title: string}|null}>, warnings: array<int, string>}
     *
     * @throws ValidationException for an expired preview or keys it doesn't hold
     */
    public function confirm(string $previewId, array $keys): array
    {
        $preview = $this->previews->find($previewId);

        if ($preview === null) {
            throw ValidationException::withMessages([
                'previewId' => NyaaImportPreviews::expiredMessage(),
            ]);
        }

        $items = collect($preview['items'])->keyBy('key');
        $unknown = array_values(array_diff($keys, $items->keys()->all()));

        if ($unknown !== []) {
            throw ValidationException::withMessages(['keys' => 'Not in this preview: '.implode(', ', $unknown).'.']);
        }

        // Used once: a second confirm can't add the same items again.
        $this->previews->forget($previewId);

        $releaseIds = $skipped = $errors = $warnings = [];
        $shows = [];
        $targets = array_column($preview['series'], 'target', 'key');
        $held = HeldEpisodes::forShows(array_values(array_filter(array_map(
            fn (array $target) => $target['kind'] === 'existing' ? $target['showId'] : null,
            $targets,
        ))));

        foreach (array_unique($keys) as $key) {
            $item = $items[$key];

            // Unparsed items are never imported (no show, no episode to file them under).
            if ($item['seriesKey'] === null) {
                $skipped[] = ['key' => $key, 'title' => $item['title'], 'reason' => "Torii couldn't read a show and episode from this title, so it can't be imported."];

                continue;
            }

            $target = $targets[$item['seriesKey']] ?? null;

            try {
                $result = $this->release($item, $target, $held, $shows, $warnings);
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
            if ($entry['linked'] !== null) {
                FetchAnimeCover::dispatchIfMissing($entry['linked']);
            }

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

        $links = ShowAnimeLink::query()
            ->whereIn('show_id', array_map(fn (array $entry) => $entry['show']->id, array_values($shows)))
            ->with(['anime' => fn ($q) => $q->select(['id', 'title_romaji', 'title_english'])])
            ->get(['show_id', 'anime_id'])
            ->keyBy('show_id');

        return [
            'queued' => count($releaseIds),
            'skipped' => $skipped,
            'errors' => $errors,
            'shows' => array_values(array_map(function (array $entry) use ($links) {
                $anime = $links->get($entry['show']->id)?->anime;

                return [
                    'id' => $entry['show']->id,
                    'name' => $entry['show']->name,
                    'created' => $entry['created'],
                    'linkedAnime' => $anime === null ? null : ['id' => $anime->id, 'title' => SeriesTargets::animeTitle($anime)],
                ];
            }, $shows)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * The release to queue for one item, or why it was skipped. Reuses Torii's
     * release with the same infohash or view URL (a SubsPlease feed release of
     * the same torrent, or an earlier import) rather than adding a duplicate.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $target  the item's series target; null for an unparsed item (no show)
     * @param  array<string, array{show: Show, created: bool, anime: int|null, linked: int|null}>  $shows  by id or name, filled as shows are found or created
     * @param  array<int, string>  $warnings
     */
    private function release(array $item, ?array $target, HeldEpisodes $held, array &$shows, array &$warnings): Release|string
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
        $entryKey = null;

        if ($target !== null) {
            $entryKey = NyaaImportPreviews::targetKey($target);
            $this->resolveShow($entryKey, $target, $shows, $warnings);
            $show = $shows[$entryKey]['show'];

            // The preview is a snapshot: the target may have got this episode since (another group's,
            // downloaded or sent). An item the preview already marked so was ticked deliberately.
            if (! $item['isBatch'] && $item['state'] !== 'have_episode' && ! $shows[$entryKey]['created']) {
                $have = $target['kind'] === 'existing'
                    ? $held->reasonFor($show->id, $item['episode'])
                    : HeldEpisodes::forShows([$show->id])->reasonFor($show->id, $item['episode']);

                if ($have !== null) {
                    return $have;
                }
            }
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
            'release_group' => $item['group'] === null ? null : mb_substr((string) $item['group'], 0, 255),
        ]);

        if ($entryKey !== null && $shows[$entryKey]['created']) {
            $this->bumpLatestEpisode($show, $item);
        }

        return $release;
    }

    /**
     * Finds or creates the target's show the first time one of its items needs
     * it, and links it to the target's anime in the same transaction. Several
     * series can land on one show (new targets merge by name): the first anime
     * offered is the one linked; a different one later is only reported.
     *
     * @param  array<string, mixed>  $target
     * @param  array<string, array{show: Show, created: bool, anime: int|null, linked: int|null}>  $shows
     * @param  array<int, string>  $warnings
     */
    private function resolveShow(string $entryKey, array $target, array &$shows, array &$warnings): void
    {
        /** @var array{id: int, title: string}|null $anime */
        $anime = $target['anime'] ?? null;

        // Shows linked by this import itself (series the user pointed at one anime under different names) aren't news.
        $ours = array_map(fn (array $entry) => $entry['show']->id, array_values($shows));

        if (! isset($shows[$entryKey])) {
            $shows[$entryKey] = DB::transaction(function () use ($target, $anime, $ours, &$warnings): array {
                $entry = $target['kind'] === 'existing' ? $this->existingShow((int) $target['showId']) : $this->show((string) $target['name']);

                return [
                    ...$entry,
                    'anime' => $anime['id'] ?? null,
                    'linked' => $anime === null ? null : $this->link($entry['show'], $anime, $ours, $warnings),
                ];
            });

            return;
        }

        $entry = &$shows[$entryKey];

        if ($anime === null || $entry['anime'] === $anime['id']) {
            return;
        }

        if ($entry['anime'] === null) {
            $entry['anime'] = $anime['id'];
            $show = $entry['show'];
            $entry['linked'] = DB::transaction(function () use ($show, $anime, $ours, &$warnings): ?int {
                return $this->link($show, $anime, $ours, $warnings);
            });

            return;
        }

        $message = "Several series went to \"{$entry['show']->name}\" with different anime: it was linked to the first one only, not to {$anime['title']}.";
        logger()->warning('Nyaa import: '.$message, ['show_id' => $entry['show']->id, 'linked_anime_id' => $entry['anime'], 'other_anime_id' => $anime['id']]);
        $warnings[] = $message;
    }

    /**
     * Links the show to the anime by hand, unless it has a link by now. Reports
     * an anime that got another show since the preview: it's linked anyway (a
     * show may share its anime), but not silently.
     *
     * @param  array{id: int, title: string}  $ref
     * @param  array<int, int>  $ours  shows this import has already found or created
     * @param  array<int, string>  $warnings
     * @return int|null the anime id when a link was made
     */
    private function link(Show $show, array $ref, array $ours, array &$warnings): ?int
    {
        $anime = Anime::find($ref['id']);

        if ($anime === null) {
            $warnings[] = "\"{$show->name}\" wasn't linked: the anime {$ref['title']} is no longer in Torii.";

            return null;
        }

        $title = SeriesTargets::animeTitle($anime);
        $current = ShowAnimeLink::where('show_id', $show->id)->first();

        if ($current !== null) {
            if ($current->anime_id !== $anime->id) {
                $warnings[] = "\"{$show->name}\" was already linked to another anime, so it wasn't linked to {$title}.";
            }

            return null;
        }

        $others = array_values(array_filter(
            SeriesTargets::linkedShowsOf([$anime->id])[$anime->id] ?? [],
            fn (array $other) => ! in_array($other['id'], $ours, true),
        ));

        $this->linker->linkManually($show, $anime);

        if ($others !== []) {
            $message = "{$title} got another show since the preview (".implode(', ', array_column($others, 'name'))."): \"{$show->name}\" is linked to it as well.";
            logger()->warning('Nyaa import: '.$message, ['show_id' => $show->id, 'anime_id' => $anime->id]);
            $warnings[] = $message;
        }

        return $anime->id;
    }

    /**
     * The show chosen in the preview.
     *
     * @return array{show: Show, created: bool}
     *
     * @throws \RuntimeException when it was deleted since
     */
    private function existingShow(int $showId): array
    {
        $show = Show::find($showId) ?? throw new \RuntimeException("the chosen show (id {$showId}) no longer exists; preview the link again");

        return ['show' => $show, 'created' => false];
    }

    /**
     * The existing show with exactly this name (one created earlier in this
     * confirm, or since the preview), or a new untracked one.
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
