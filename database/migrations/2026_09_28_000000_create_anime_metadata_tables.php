<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anime', function (Blueprint $table) {
            $table->id();
            $table->string('title_romaji')->nullable();
            $table->string('title_english')->nullable();
            $table->string('title_native')->nullable();
            $table->jsonb('synonyms')->default('[]');
            $table->text('description')->nullable();
            $table->jsonb('genres')->default('[]');
            $table->string('format')->nullable();
            $table->string('status')->nullable();
            $table->unsignedInteger('episodes_total')->nullable();
            $table->unsignedInteger('episodes_aired')->nullable();
            $table->string('season')->nullable();
            $table->unsignedSmallInteger('season_year')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->text('cover_url')->nullable();
            $table->text('banner_url')->nullable();
            $table->text('site_url')->nullable();
            $table->boolean('is_adult')->default(false);
            $table->string('primary_provider');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['season_year', 'season']);
        });

        Schema::create('anime_external_ids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->string('provider');
            $table->string('external_id');
            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->unique(['anime_id', 'provider']);
        });

        Schema::create('anime_payloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->string('provider');
            $table->jsonb('payload');
            $table->timestamp('fetched_at');
            $table->timestamps();

            $table->unique(['anime_id', 'provider']);
        });

        Schema::create('anime_airings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->string('provider');
            $table->unsignedInteger('episode');
            $table->timestamp('airs_at');
            $table->boolean('is_estimate')->default(false);
            $table->timestamps();

            $table->unique(['anime_id', 'provider', 'episode']);
            $table->index('airs_at');
        });

        Schema::create('anime_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('anime_id')->unique()->constrained('anime')->cascadeOnDelete();
            $table->text('source_url');
            $table->string('mime');
            $table->text('data');
            $table->unsignedInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('sha256', 64);
            $table->timestamp('fetched_at');
            $table->timestamps();
        });

        Schema::create('show_anime_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->unique()->constrained('shows')->cascadeOnDelete();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->unsignedTinyInteger('confidence');
            $table->string('source');
            $table->timestamp('linked_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_anime_links');
        Schema::dropIfExists('anime_images');
        Schema::dropIfExists('anime_airings');
        Schema::dropIfExists('anime_payloads');
        Schema::dropIfExists('anime_external_ids');
        Schema::dropIfExists('anime');
    }
};
