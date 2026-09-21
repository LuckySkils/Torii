<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->string('latest_episode')->nullable();
            $table->boolean('is_tracked')->default(false);
            $table->timestamp('tracked_at')->nullable();
            $table->string('rule_state')->default('none');
            $table->timestamp('rule_synced_at')->nullable();
            $table->text('rule_error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shows');
    }
};
