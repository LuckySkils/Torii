<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a release came from (§16): SubsPlease's RSS feed, or a Nyaa import.
 * Existing rows are all from the feed. A Nyaa release keeps its .torrent URL as
 * a fallback; `link` holds the magnet built from its infohash.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('source')->default('subsplease_rss');
            $table->text('torrent_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['source', 'torrent_url']);
        });
    }
};
