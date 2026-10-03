<?php

declare(strict_types=1);

namespace App\Services\Metadata;

use App\Models\Anime;
use Illuminate\Support\Facades\DB;

/**
 * Replaces one anime's rows in `anime_genre`, `anime_tag` and `anime_studio` with
 * what the sync just parsed, creating `genres`/`tags`/`studios` rows as needed.
 * The payload stays the source of truth; these tables exist so queries join
 * instead of unpacking JSON. The migrations' backfills read stored payloads the
 * same way.
 */
final class AnimeTaxonomyWriter
{
    /**
     * @param  array<int, string>  $genres
     * @param  array<int, ProviderTag>  $tags  unique by name
     * @param  array<int, string>  $studios  unique
     */
    public function replace(Anime $anime, array $genres, array $tags, array $studios): void
    {
        $genres = array_values(array_unique($genres));
        $genreIds = $this->ids('genres', $genres);
        DB::table('anime_genre')->where('anime_id', $anime->id)->delete();

        if ($genres !== []) {
            DB::table('anime_genre')->insert(array_map(fn (string $genre) => [
                'anime_id' => $anime->id,
                'genre_id' => $genreIds[$genre],
            ], $genres));
        }

        $tagIds = $this->ids('tags', array_map(fn (ProviderTag $tag) => $tag->name, $tags));
        DB::table('anime_tag')->where('anime_id', $anime->id)->delete();

        if ($tags !== []) {
            DB::table('anime_tag')->insert(array_map(fn (ProviderTag $tag) => [
                'anime_id' => $anime->id,
                'tag_id' => $tagIds[$tag->name],
                'rank' => $tag->rank,
                'is_spoiler' => $tag->isSpoiler,
            ], $tags));
        }

        $studioIds = $this->ids('studios', $studios);
        DB::table('anime_studio')->where('anime_id', $anime->id)->delete();

        if ($studios !== []) {
            DB::table('anime_studio')->insert(array_map(fn (string $studio) => [
                'anime_id' => $anime->id,
                'studio_id' => $studioIds[$studio],
            ], $studios));
        }
    }

    /**
     * Adds every genre and tag the provider knows to `genres`/`tags`, used or not,
     * and refreshes each tag's category, description and adult flag. Additive:
     * names no longer listed are kept, since anime may still carry them. No pivot
     * rows are touched.
     */
    public function addVocabulary(ProviderVocabulary $vocabulary): void
    {
        $this->ids('genres', $vocabulary->genres);

        foreach (array_chunk($vocabulary->tags, 200) as $chunk) {
            DB::table('tags')->upsert(
                array_map(fn (ProviderTagDefinition $tag) => [
                    'name' => $tag->name,
                    'category' => $tag->category,
                    'description' => $tag->description,
                    'is_adult' => $tag->isAdult,
                ], $chunk),
                ['name'],
                ['category', 'description', 'is_adult'],
            );
        }
    }

    /**
     * @param  array<int, string>  $names
     * @return array<string, int>
     */
    private function ids(string $table, array $names): array
    {
        if ($names === []) {
            return [];
        }

        DB::table($table)->insertOrIgnore(array_map(fn (string $name) => ['name' => $name], $names));

        return DB::table($table)->whereIn('name', $names)->pluck('id', 'name')->map(fn ($id) => (int) $id)->all();
    }
}
