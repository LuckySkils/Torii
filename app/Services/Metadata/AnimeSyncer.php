<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;
use App\Models\AnimeExternalId;
use App\Models\AnimePayload;
use Illuminate\Support\Facades\DB;

/**
 * Writes one ProviderAnime into `anime`, `anime_external_ids` and
 * `anime_payloads`. Idempotent: an entry is found by its provider id, and the
 * payload row is replaced, never duplicated.
 */
final class AnimeSyncer
{
    public function __construct(private readonly AnimeAiringWriter $airings) {}

    public function upsert(ProviderAnime $entry): AnimeSyncResult
    {
        return DB::transaction(function () use ($entry): AnimeSyncResult {
            $anime = AnimeExternalId::query()
                ->where('provider', $entry->provider)
                ->where('external_id', $entry->externalId)
                ->first()
                ?->anime;

            $created = $anime === null;
            $anime ??= new Anime;

            $anime->fill([
                'title_romaji' => $entry->titleRomaji,
                'title_english' => $entry->titleEnglish,
                'title_native' => $entry->titleNative,
                'synonyms' => $entry->synonyms,
                'description' => $entry->description,
                'genres' => $entry->genres,
                'format' => $entry->format,
                'status' => $entry->status,
                'episodes_total' => $entry->episodesTotal,
                'season' => $entry->season,
                'season_year' => $entry->seasonYear,
                'start_date' => $entry->startDate,
                'end_date' => $entry->endDate,
                'duration_minutes' => $entry->durationMinutes,
                'cover_url' => $entry->coverUrl,
                'banner_url' => $entry->bannerUrl,
                'site_url' => $entry->siteUrl,
                'is_adult' => $entry->isAdult,
                'primary_provider' => $entry->provider,
                'synced_at' => now(),
            ]);

            $coverChanged = ! $created && $anime->isDirty('cover_url');
            $anime->save();

            $this->saveExternalId($anime, $entry->provider, $entry->externalId);

            foreach ($entry->otherExternalIds as $provider => $externalId) {
                $this->saveExternalId($anime, $provider, $externalId);
            }

            AnimePayload::updateOrCreate(
                ['anime_id' => $anime->id, 'provider' => $entry->provider],
                ['payload' => $entry->raw, 'fetched_at' => now()],
            );

            if ($entry->nextAiring !== null) {
                $this->airings->merge($anime, $entry->provider, [$entry->nextAiring]);
            } else {
                $this->airings->refreshEpisodesAired($anime);
            }

            return new AnimeSyncResult($anime, $created, $coverChanged);
        });
    }

    /**
     * Cross-references (e.g. AniList's idMal) are free extras: if another anime
     * already claims the same one, keep the first and log, never fail the sync.
     */
    private function saveExternalId(Anime $anime, string $provider, string $externalId): void
    {
        $owner = AnimeExternalId::query()
            ->where('provider', $provider)
            ->where('external_id', $externalId)
            ->value('anime_id');

        if ($owner !== null && $owner !== $anime->id) {
            logger()->warning('External id already belongs to another anime; skipped', [
                'provider' => $provider,
                'external_id' => $externalId,
                'anime_id' => $anime->id,
                'owner_anime_id' => $owner,
            ]);

            return;
        }

        AnimeExternalId::updateOrCreate(
            ['anime_id' => $anime->id, 'provider' => $provider],
            ['external_id' => $externalId],
        );
    }
}
