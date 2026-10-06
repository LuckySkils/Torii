<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use App\Enums\DeliveryState;
use App\Enums\FixAttempted;
use App\Enums\NotificationKind;
use App\Jobs\Reconciler\CheckDeliveryInJellyfin;
use App\Jobs\Reconciler\CheckSeriesEpisodes;
use App\Jobs\Reconciler\FinishLibraryRefresh;
use App\Jobs\SendNotification;
use App\Models\Delivery;
use App\Models\NotificationLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * The reconciler's decisions (§17), run by queued jobs on the main worker; the
 * listener only records events. Only the two verified failures are acted on,
 * and only for new shows (a `file.matched` with no Shoko series yet):
 *
 *  - Check A (trigger: Shoko's own `series.added` for the series): is the episode
 *    in Jellyfin (recently-added lookup by `Shoko File`)? Not after the backoff →
 *    fix A, one Anime-library refresh shared by every failure A while it runs,
 *    complete on the next `library.changed` (or a timeout) → check A again →
 *    found: check B, else give up.
 *  - Check B (trigger: `library.changed`, or straight after check A): does the
 *    episode's series list any episodes? Not after the backoff → fix B, a series
 *    refresh with replace-all (no completion event: re-checked from when the
 *    request returns) → listed: playable, else give up.
 *
 * Every check runs on the backoff (3, 7, 15 s after its trigger) as delayed jobs;
 * each fix runs at most once per confirmed failure; giving up notifies. Dry run
 * records what would have been sent instead of sending it. Existing shows only
 * advance their trail when a `library.changed` lookup finds them.
 */
final class DeliveryReconciler
{
    public const INITIAL = 'initial';

    public const AFTER_FIX_A = 'after_fix_a';

    public const AFTER_FIX_B = 'after_fix_b';

    private const FIX_A = 'reconciler:fix-a';

    private const FIX_A_LOCK = 'reconciler:fix-a:lock';

    private const SERIES_ADDED = 'reconciler:shoko-series-added:';

    public function __construct(private readonly JellyfinClient $jellyfin) {}

    // ── Correlation ──────────────────────────────────────────────────────────

    /**
     * A file Torii downloaded, matched by Shoko: remember its Shoko file, AniDB
     * anime and (for an existing show) Shoko series. Unknown files are ignored.
     *
     * @param  array<string, mixed>  $payload
     */
    public function fileMatched(array $payload): void
    {
        $filename = ShokoEvents::filename($payload);
        $delivery = $filename === null ? null : Delivery::where('filename', $filename)->first();

        if ($delivery === null) {
            return;
        }

        $seriesId = ShokoEvents::firstReference($payload, 'SeriesID');
        $isNew = $seriesId === null;

        $delivery->fill([
            'shoko_file_id' => ShokoEvents::fileId($payload),
            'anidb_anime_id' => ShokoEvents::firstReference($payload, 'AnidbAnimeID'),
            'shoko_series_id' => $seriesId ?? $delivery->shoko_series_id,
            'is_new_show' => $isNew,
            'matched_at' => $delivery->matched_at ?? now(),
        ]);

        if ($delivery->state === DeliveryState::Downloaded) {
            $delivery->state = $isNew ? DeliveryState::AwaitingSeries : DeliveryState::Matched;
        }

        $delivery->save();
    }

    /**
     * `series.added`. From AniDB (SeriesID = the AniDB anime): map it to its Shoko
     * series on the waiting deliveries. From Shoko itself: check A's trigger.
     * Either order works: each side remembers the other's arrival.
     *
     * @param  array<string, mixed>  $payload
     */
    public function seriesAdded(array $payload): void
    {
        $shokoIds = array_values(array_map('intval', array_filter((array) ($payload['ShokoSeriesIDs'] ?? []), 'is_numeric')));
        $seriesId = is_numeric($payload['SeriesID'] ?? null) ? (int) $payload['SeriesID'] : null;

        if (($payload['Source'] ?? null) === 'AniDB') {
            if ($seriesId !== null && $shokoIds !== []) {
                Delivery::where('anidb_anime_id', $seriesId)->whereNull('shoko_series_id')->update(['shoko_series_id' => $shokoIds[0]]);
            }

            foreach ($shokoIds as $shokoId) {
                if (Cache::has(self::SERIES_ADDED.$shokoId)) {
                    $this->startCheckAForSeries($shokoId);
                }
            }

            return;
        }

        // Shoko's own series (the newer naming has no Source field: treated the same).
        foreach ($shokoIds ?: array_filter([$seriesId]) as $shokoId) {
            Cache::put(self::SERIES_ADDED.$shokoId, true, now()->addDay());
            $this->startCheckAForSeries($shokoId);
        }
    }

    /**
     * Jellyfin finished refreshing something in the Anime library: completes a
     * running fix A, triggers check B for new-show episodes waiting on their
     * series, and moves existing shows' trails along (no checks, no fixes).
     */
    public function libraryChanged(): void
    {
        $this->finishLibraryRefresh(null);

        Delivery::where('state', DeliveryState::InJellyfin)->where('is_new_show', true)->get()
            ->each(fn (Delivery $delivery) => $this->startCheckB($delivery, self::INITIAL));

        $this->advanceExistingShows();
    }

    // ── Check A: did the episode reach Jellyfin? ─────────────────────────────

    public function startCheckA(Delivery $delivery, string $phase): void
    {
        CheckDeliveryInJellyfin::dispatch($delivery->id, $delivery->run, $phase, 1)->delay($this->delayBefore(1));
    }

    public function checkA(int $deliveryId, int $run, string $phase, int $attempt): void
    {
        $delivery = $this->current($deliveryId, $run, $phase === self::INITIAL ? DeliveryState::AwaitingSeries : DeliveryState::FixingA);

        if ($delivery === null) {
            return;
        }

        try {
            $episode = $this->jellyfin->findEpisodeByShokoFile((int) $delivery->shoko_file_id);
        } catch (Throwable $e) {
            $episode = null;
            $delivery->update(['last_error' => $e->getMessage()]);
        }

        if ($episode !== null) {
            $delivery->update([
                'jellyfin_episode_id' => $episode['id'],
                'jellyfin_series_id' => $episode['seriesId'],
                'state' => DeliveryState::InJellyfin,
                'in_jellyfin_at' => now(),
                'last_error' => null,
            ]);
            // Fix A itself produces failure B, so check B always follows.
            $this->startCheckB($delivery, self::INITIAL);

            return;
        }

        match (true) {
            $attempt < $this->attempts() => CheckDeliveryInJellyfin::dispatch($delivery->id, $run, $phase, $attempt + 1)->delay($this->delayBefore($attempt + 1)),
            $phase === self::INITIAL => $this->fixA($delivery),
            default => $this->giveUp($delivery, 'Failure A: the episode never reached Jellyfin, even after the Anime library refresh.'),
        };
    }

    /**
     * Fix A: one Anime-library refresh at a time. A failure A arriving while one
     * runs joins it instead of starting another.
     */
    private function fixA(Delivery $delivery): void
    {
        $delivery->update(['state' => DeliveryState::FixingA, 'fix_attempted' => FixAttempted::A]);
        $dryRun = (bool) config('subtracker.reconciler.dry_run');

        $start = Cache::lock(self::FIX_A_LOCK, 10)->block(5, function () use ($delivery): ?string {
            $refresh = Cache::get(self::FIX_A);

            if (is_array($refresh)) {
                $refresh['deliveries'][] = [$delivery->id, $delivery->run];
                Cache::put(self::FIX_A, $refresh, $this->refreshMarkerTtl());
                logger()->info('Reconciler: failure A joins the running Anime library refresh', ['delivery' => $delivery->id]);

                return null;
            }

            $token = (string) Str::uuid();
            Cache::put(self::FIX_A, ['token' => $token, 'startedAt' => now()->toIso8601String(), 'deliveries' => [[$delivery->id, $delivery->run]]], $this->refreshMarkerTtl());

            return $token;
        });

        if ($dryRun) {
            $this->wouldHave($delivery, 'fix A (refresh the Anime library)');
        }

        if ($start === null) {
            return;
        }

        if (! $dryRun) {
            try {
                $this->jellyfin->refreshAnimeLibrary();
                logger()->info('Reconciler: fix A sent (Anime library refresh)', ['delivery' => $delivery->id]);
            } catch (Throwable $e) {
                logger()->error('Reconciler: fix A request failed', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);
                $delivery->update(['last_error' => 'Fix A request failed: '.$e->getMessage()]);
            }
        }

        // Safety net when no library.changed arrives (or, in dry run, nothing was refreshed).
        FinishLibraryRefresh::dispatch($start)->delay((int) config('subtracker.reconciler.library_refresh_timeout'));
    }

    /**
     * The running fix A is over (its library.changed, or the timeout for that
     * refresh's token): check A again for every delivery in it, on the backoff.
     */
    public function finishLibraryRefresh(?string $token): void
    {
        $batch = Cache::lock(self::FIX_A_LOCK, 10)->block(5, function () use ($token): array {
            $refresh = Cache::get(self::FIX_A);

            if (! is_array($refresh) || ($token !== null && $refresh['token'] !== $token)) {
                return [];
            }

            Cache::forget(self::FIX_A);

            return $refresh['deliveries'];
        });

        foreach ($batch as [$deliveryId, $run]) {
            CheckDeliveryInJellyfin::dispatch($deliveryId, $run, self::AFTER_FIX_A, 1)->delay($this->delayBefore(1));
        }

        if ($batch !== []) {
            logger()->info('Reconciler: Anime library refresh finished', ['deliveries' => count($batch), 'by' => $token === null ? 'library.changed' : 'timeout']);
        }
    }

    // ── Check B: does the series list its episodes? ──────────────────────────

    public function startCheckB(Delivery $delivery, string $phase): void
    {
        CheckSeriesEpisodes::dispatch($delivery->id, $delivery->run, $phase, 1)->delay($this->delayBefore(1));
    }

    public function checkB(int $deliveryId, int $run, string $phase, int $attempt): void
    {
        $delivery = $this->current($deliveryId, $run, $phase === self::INITIAL ? DeliveryState::InJellyfin : DeliveryState::FixingB);

        if ($delivery === null) {
            return;
        }

        try {
            // Through the Shoko File id every time: the series id can change after a replace.
            $episode = $this->jellyfin->findEpisodeByShokoFile((int) $delivery->shoko_file_id);

            if ($episode !== null && $episode['seriesId'] !== null) {
                $delivery->update(['jellyfin_episode_id' => $episode['id'], 'jellyfin_series_id' => $episode['seriesId']]);

                if ($this->jellyfin->seriesEpisodeCount($episode['seriesId']) > 0) {
                    $this->playable($delivery);

                    return;
                }
            }
        } catch (Throwable $e) {
            $delivery->update(['last_error' => $e->getMessage()]);
        }

        match (true) {
            $attempt < $this->attempts() => CheckSeriesEpisodes::dispatch($delivery->id, $run, $phase, $attempt + 1)->delay($this->delayBefore($attempt + 1)),
            $phase === self::INITIAL && $delivery->jellyfin_series_id !== null => $this->fixB($delivery),
            $phase === self::INITIAL => $this->giveUp($delivery, 'Failure B: the episode left Jellyfin before its series could be refreshed.'),
            default => $this->giveUp($delivery, 'Failure B: the series still lists no episodes after the series refresh.'),
        };
    }

    /** Fix B: refresh that one series, replacing all metadata. */
    private function fixB(Delivery $delivery): void
    {
        $delivery->update(['state' => DeliveryState::FixingB, 'fix_attempted' => $delivery->fix_attempted->withB()]);

        if (config('subtracker.reconciler.dry_run')) {
            $this->wouldHave($delivery, "fix B (refresh series {$delivery->jellyfin_series_id})");
        } else {
            try {
                $this->jellyfin->refreshSeries((string) $delivery->jellyfin_series_id);
                logger()->info('Reconciler: fix B sent (series refresh)', ['delivery' => $delivery->id, 'series' => $delivery->jellyfin_series_id]);
            } catch (Throwable $e) {
                logger()->error('Reconciler: fix B request failed', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);
                $delivery->update(['last_error' => 'Fix B request failed: '.$e->getMessage()]);
            }
        }

        // No completion event: the backoff starts when the request has returned.
        CheckSeriesEpisodes::dispatch($delivery->id, $delivery->run, self::AFTER_FIX_B, 1)->delay($this->delayBefore(1));
    }

    // ── Outcomes ─────────────────────────────────────────────────────────────

    private function playable(Delivery $delivery): void
    {
        $delivery->update(['state' => DeliveryState::Playable, 'playable_at' => now(), 'last_error' => null]);

        if ($delivery->fix_attempted !== FixAttempted::None && config('subtracker.reconciler.notify_on_fix') && ! config('subtracker.reconciler.dry_run')) {
            $this->notify(NotificationKind::DeliveryFixed, $delivery);
        }
    }

    private function giveUp(Delivery $delivery, string $reason): void
    {
        $dryRun = (bool) config('subtracker.reconciler.dry_run');

        $delivery->update([
            'state' => DeliveryState::GaveUp,
            'gave_up_at' => now(),
            'last_error' => $dryRun ? $reason.' (Dry run: no fix was sent.)' : $reason,
        ]);

        logger()->warning('Reconciler gave up on a delivery', ['delivery' => $delivery->id, 'reason' => $reason, 'dry_run' => $dryRun]);

        if (! $dryRun) {
            $this->notify(NotificationKind::DeliveryGaveUp, $delivery);
        }
    }

    /**
     * The manual "repair" action: run the chain again from the start for this
     * delivery (dry run still applies). Jobs from the earlier run are ignored.
     */
    public function repair(Delivery $delivery): void
    {
        $state = match (true) {
            $delivery->shoko_file_id === null => DeliveryState::Downloaded,
            $delivery->is_new_show => DeliveryState::AwaitingSeries,
            default => DeliveryState::Matched,
        };

        $delivery->update([
            'run' => $delivery->run + 1,
            'state' => $state,
            'fix_attempted' => FixAttempted::None,
            'would_have_fixed' => null,
            'last_error' => null,
            'in_jellyfin_at' => null,
            'playable_at' => null,
            'gave_up_at' => null,
        ]);

        NotificationLog::where('release_id', $delivery->release_id)
            ->whereIn('kind', [NotificationKind::DeliveryFixed, NotificationKind::DeliveryGaveUp])
            ->delete();

        // A new show's series exists by now, so its chain starts at check A.
        if ($state === DeliveryState::AwaitingSeries) {
            $this->startCheckA($delivery, self::INITIAL);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Existing shows need no checks or fixes, but their trail still moves when
     * Jellyfin has the episode (one lookup) and its series lists episodes.
     */
    private function advanceExistingShows(): void
    {
        $waiting = Delivery::where('state', DeliveryState::Matched)->where('is_new_show', false)->whereNotNull('shoko_file_id')->get();

        foreach ($waiting as $delivery) {
            try {
                $episode = $this->jellyfin->findEpisodeByShokoFile((int) $delivery->shoko_file_id);

                if ($episode === null) {
                    continue;
                }

                $delivery->update([
                    'jellyfin_episode_id' => $episode['id'],
                    'jellyfin_series_id' => $episode['seriesId'],
                    'state' => DeliveryState::InJellyfin,
                    'in_jellyfin_at' => now(),
                ]);

                if ($episode['seriesId'] !== null && $this->jellyfin->seriesEpisodeCount($episode['seriesId']) > 0) {
                    $delivery->update(['state' => DeliveryState::Playable, 'playable_at' => now()]);
                }
            } catch (Throwable $e) {
                logger()->warning('Reconciler: trail lookup failed', ['delivery' => $delivery->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function startCheckAForSeries(int $shokoSeriesId): void
    {
        Delivery::where('state', DeliveryState::AwaitingSeries)->where('shoko_series_id', $shokoSeriesId)->get()
            ->each(fn (Delivery $delivery) => $this->startCheckA($delivery, self::INITIAL));
    }

    /** The delivery, if it's still on this run and in the state the job expects. */
    private function current(int $deliveryId, int $run, DeliveryState $expected): ?Delivery
    {
        $delivery = Delivery::find($deliveryId);

        return $delivery !== null && $delivery->run === $run && $delivery->state === $expected ? $delivery : null;
    }

    private function wouldHave(Delivery $delivery, string $fix): void
    {
        $delivery->update(['would_have_fixed' => trim(($delivery->would_have_fixed === null ? '' : $delivery->would_have_fixed.', then ').$fix)]);
        logger()->info('Reconciler dry run: would have run '.$fix, ['delivery' => $delivery->id]);
    }

    private function notify(NotificationKind $kind, Delivery $delivery): void
    {
        if (config('subtracker.notifications.enabled')) {
            SendNotification::dispatch($kind, $delivery->release_id);
        }
    }

    private function attempts(): int
    {
        return count((array) config('subtracker.reconciler.backoff'));
    }

    /** Seconds before attempt n: the backoff is in seconds after the trigger (3, 7, 15). */
    private function delayBefore(int $attempt): int
    {
        $backoff = array_values((array) config('subtracker.reconciler.backoff'));

        return (int) $backoff[$attempt - 1] - ($attempt > 1 ? (int) $backoff[$attempt - 2] : 0);
    }

    private function refreshMarkerTtl(): \DateTimeInterface
    {
        return now()->addSeconds((int) config('subtracker.reconciler.library_refresh_timeout') + 600);
    }
}
