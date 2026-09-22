<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_log', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->foreignId('release_id')->nullable()->constrained('releases')->cascadeOnDelete();
            $table->string('status');
            $table->text('error')->nullable();
            $table->timestamp('sent_at');
            $table->timestamps();
        });

        // Every non-test notification may be sent at most once per (kind, release);
        // "test" is exempt so the test button can be pressed repeatedly.
        DB::statement(
            "CREATE UNIQUE INDEX notification_log_kind_release_unique ON notification_log (kind, release_id) WHERE kind != 'test'"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_log');
    }
};
