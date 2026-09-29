<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use Illuminate\Support\Facades\DB;

/**
 * Points every anime's cover_url at AniList's largest cover, read from the
 * payload already stored: no provider requests. Run by the migration that
 * switched to extraLarge; safe to run again (it only touches rows that differ).
 */
final class CoverUrls
{
    /** @return int anime whose cover_url changed */
    public function useLargest(): int
    {
        return DB::update(<<<'SQL'
            UPDATE anime
            SET cover_url = payload.payload -> 'coverImage' ->> 'extraLarge'
            FROM anime_payloads AS payload
            WHERE payload.anime_id = anime.id
              AND payload.provider = anime.primary_provider
              AND payload.payload -> 'coverImage' ->> 'extraLarge' IS NOT NULL
              AND anime.cover_url IS DISTINCT FROM payload.payload -> 'coverImage' ->> 'extraLarge'
            SQL);
    }
}
