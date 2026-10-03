<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Genres as a table (§11), like tags and studios: written by the AniList sync from
 * the payload, which stays the source of truth. suggest_anime joins these instead
 * of unpacking JSON (§15). `anime.genres` stays for the filters and list rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('anime_genre', function (Blueprint $table) {
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['anime_id', 'genre_id']);
            // Rarity counts and the scoring join go by genre.
            $table->index(['genre_id', 'anime_id']);
        });

        $this->backfill();
    }

    /**
     * Fills the tables from every anime's primary-provider payload, the way
     * AniListMediaParser reads `genres`: non-blank strings, each once. Idempotent.
     * Public so tests can run it against seeded payloads.
     */
    public function backfill(): void
    {
        $genres = <<<'SQL'
            FROM anime_payloads p
            JOIN anime a ON a.id = p.anime_id AND p.provider = a.primary_provider
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN jsonb_typeof(p.payload -> 'genres') = 'array' THEN p.payload -> 'genres' ELSE '[]'::jsonb END
            ) AS g(value)
            SQL;
        $named = "jsonb_typeof(g.value) = 'string' AND btrim(g.value #>> '{}') <> ''";

        DB::statement("INSERT INTO genres (name) SELECT DISTINCT g.value #>> '{}' {$genres} WHERE {$named} ON CONFLICT (name) DO NOTHING");

        DB::statement(<<<SQL
            INSERT INTO anime_genre (anime_id, genre_id)
            SELECT DISTINCT p.anime_id, genre.id
            {$genres}
            JOIN genres genre ON genre.name = g.value #>> '{}'
            WHERE {$named}
            ON CONFLICT (anime_id, genre_id) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_genre');
        Schema::dropIfExists('genres');
    }
};
