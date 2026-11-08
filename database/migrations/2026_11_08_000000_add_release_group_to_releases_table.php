<?php

use App\Services\Releases\ReleaseTitleParser;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The release group that made a release (§4, §16): "SubsPlease" for every feed
 * release, the parsed `[Group]` for a Nyaa import (null when its title doesn't
 * parse). Named `release_group` because `group` is an SQL keyword; props call it
 * `group`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('release_group')->nullable();
            $table->index(['show_id', 'release_group']);
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropIndex(['show_id', 'release_group']);
            $table->dropColumn('release_group');
        });
    }

    /** Idempotent: only fills rows that have no group yet. */
    public function backfill(): void
    {
        DB::table('releases')
            ->whereNull('release_group')
            ->where('source', 'subsplease_rss')
            ->update(['release_group' => ReleaseTitleParser::SUBSPLEASE]);

        $parser = new ReleaseTitleParser;

        DB::table('releases')
            ->whereNull('release_group')
            ->where('source', 'nyaa')
            ->orderBy('id')
            ->select(['id', 'title'])
            ->chunkById(500, function ($rows) use ($parser) {
                foreach ($rows as $row) {
                    $group = $parser->parse((string) $row->title)->group;

                    if ($group !== null) {
                        DB::table('releases')->where('id', $row->id)->update(['release_group' => mb_substr($group, 0, 255)]);
                    }
                }
            });
    }
};
