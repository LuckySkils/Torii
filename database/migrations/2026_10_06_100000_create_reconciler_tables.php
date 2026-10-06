<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery reconciler (§17): the events the listener keeps, and one delivery per
 * downloaded episode with its trail towards being playable in Jellyfin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciler_events', function (Blueprint $table) {
            $table->id();
            $table->string('source'); // shoko | jellyfin
            $table->string('type'); // App\Enums\ReconcilerEventType
            $table->string('raw_target');
            $table->jsonb('payload'); // the raw argument / Data, as received
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->index('received_at');
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('release_id')->unique()->constrained('releases')->cascadeOnDelete();
            // The release's file name, which Shoko reports as RelativePath (compared by basename).
            $table->string('filename')->index();
            $table->unsignedBigInteger('shoko_file_id')->nullable()->index();
            $table->unsignedBigInteger('anidb_anime_id')->nullable()->index();
            $table->unsignedBigInteger('shoko_series_id')->nullable()->index();
            $table->boolean('is_new_show')->default(false);
            $table->string('jellyfin_episode_id')->nullable();
            $table->string('jellyfin_series_id')->nullable();
            $table->string('state')->default('downloaded')->index(); // App\Enums\DeliveryState
            $table->string('fix_attempted')->default('none'); // App\Enums\FixAttempted
            // Dry run: the fixes that would have been sent.
            $table->string('would_have_fixed')->nullable();
            $table->text('last_error')->nullable();
            // Bumped by a manual repair, so jobs from an earlier run are ignored.
            $table->unsignedInteger('run')->default(1);
            $table->timestamp('matched_at')->nullable();
            $table->timestamp('in_jellyfin_at')->nullable();
            $table->timestamp('playable_at')->nullable();
            $table->timestamp('gave_up_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('reconciler_events');
    }
};
