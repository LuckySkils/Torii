<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Format and genre counts for filter options, computed in SQL. Counts are over
 * everything known, not the current filter result, so an option never vanishes
 * because another filter hid it. For shows, counts are shows whose linked anime
 * has the value.
 */
final class AnimeFacets
{
    /**
     * @return array<int, array{value: string, count: int}> in Anime::FORMATS order
     */
    public function formats(bool $linkedShowsOnly = false): array
    {
        $counts = $this->base($linkedShowsOnly)
            ->whereNotNull('anime.format')
            ->groupBy('anime.format')
            ->selectRaw('anime.format as value, count(*) as count')
            ->pluck('count', 'value');

        $known = array_values(array_filter(Anime::FORMATS, fn (string $format) => isset($counts[$format])));
        $other = array_values(array_diff($counts->keys()->all(), Anime::FORMATS));

        return array_map(fn (string $format) => ['value' => $format, 'count' => (int) $counts[$format]], [...$known, ...$other]);
    }

    /**
     * @return array<int, array{value: string, count: int}> alphabetical
     */
    public function genres(bool $linkedShowsOnly = false): array
    {
        return $this->base($linkedShowsOnly)
            ->crossJoin(DB::raw('lateral jsonb_array_elements_text(anime.genres) as genre(value)'))
            ->groupBy('genre.value')
            ->orderBy('genre.value')
            ->selectRaw('genre.value as value, count(*) as count')
            ->get()
            ->map(fn (object $row) => ['value' => (string) $row->value, 'count' => (int) $row->count])
            ->all();
    }

    private function base(bool $linkedShowsOnly): Builder
    {
        // show_id is unique in show_anime_links, so one row per show.
        return $linkedShowsOnly
            ? DB::table('show_anime_links')->join('anime', 'anime.id', '=', 'show_anime_links.anime_id')
            : DB::table('anime');
    }
}
