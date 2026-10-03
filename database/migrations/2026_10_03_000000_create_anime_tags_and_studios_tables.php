<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tags and studios as tables (§11), written by the AniList sync from the payload,
 * which stays the source of truth. suggest_anime scores with joins and counts
 * over these instead of unpacking payload JSON per request (§15).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('anime_tag', function (Blueprint $table) {
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            // AniList's relevance of the tag to this anime, 0–100.
            $table->smallInteger('rank');
            $table->boolean('is_spoiler')->default(false);
            $table->primary(['anime_id', 'tag_id']);
            // Rarity counts and the scoring join go by tag.
            $table->index(['tag_id', 'anime_id']);
        });

        Schema::create('studios', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
        });

        Schema::create('anime_studio', function (Blueprint $table) {
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->foreignId('studio_id')->constrained()->cascadeOnDelete();
            $table->primary(['anime_id', 'studio_id']);
            $table->index(['studio_id', 'anime_id']);
        });

        $this->backfill();
    }

    /**
     * Fills the tables from every anime's primary-provider payload, the same way
     * AniListMediaParser reads it: named tags (highest rank kept if one repeats,
     * missing rank 0, spoiler only when flagged true) and named main studios.
     * Idempotent. Public so tests can run it against seeded payloads.
     */
    public function backfill(): void
    {
        $tags = <<<'SQL'
            FROM anime_payloads p
            JOIN anime a ON a.id = p.anime_id AND p.provider = a.primary_provider
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN jsonb_typeof(p.payload -> 'tags') = 'array' THEN p.payload -> 'tags' ELSE '[]'::jsonb END
            ) AS t(value)
            SQL;
        $namedTag = "jsonb_typeof(t.value -> 'name') = 'string' AND btrim(t.value ->> 'name') <> ''";

        $studios = <<<'SQL'
            FROM anime_payloads p
            JOIN anime a ON a.id = p.anime_id AND p.provider = a.primary_provider
            CROSS JOIN LATERAL jsonb_array_elements(
                CASE WHEN jsonb_typeof(p.payload -> 'studios' -> 'nodes') = 'array' THEN p.payload -> 'studios' -> 'nodes' ELSE '[]'::jsonb END
            ) AS s(value)
            SQL;
        $namedStudio = "jsonb_typeof(s.value -> 'name') = 'string' AND btrim(s.value ->> 'name') <> ''";

        DB::statement("INSERT INTO tags (name) SELECT DISTINCT t.value ->> 'name' {$tags} WHERE {$namedTag} ON CONFLICT (name) DO NOTHING");

        DB::statement(<<<SQL
            INSERT INTO anime_tag (anime_id, tag_id, rank, is_spoiler)
            SELECT DISTINCT ON (p.anime_id, tag.id)
                p.anime_id,
                tag.id,
                CASE WHEN jsonb_typeof(t.value -> 'rank') = 'number' THEN CAST(trunc(CAST(t.value ->> 'rank' AS numeric)) AS int) ELSE 0 END AS tag_rank,
                COALESCE(t.value -> 'isMediaSpoiler' = 'true'::jsonb, false)
            {$tags}
            JOIN tags tag ON tag.name = t.value ->> 'name'
            WHERE {$namedTag}
            ORDER BY p.anime_id, tag.id, tag_rank DESC
            ON CONFLICT (anime_id, tag_id) DO NOTHING
            SQL);

        DB::statement("INSERT INTO studios (name) SELECT DISTINCT s.value ->> 'name' {$studios} WHERE {$namedStudio} ON CONFLICT (name) DO NOTHING");

        DB::statement(<<<SQL
            INSERT INTO anime_studio (anime_id, studio_id)
            SELECT DISTINCT p.anime_id, studio.id
            {$studios}
            JOIN studios studio ON studio.name = s.value ->> 'name'
            WHERE {$namedStudio}
            ON CONFLICT (anime_id, studio_id) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('anime_studio');
        Schema::dropIfExists('studios');
        Schema::dropIfExists('anime_tag');
        Schema::dropIfExists('tags');
    }
};
