<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the vocabulary sync knows about a tag beyond its name (§15): AniList's
 * category ("Theme-Fantasy", "Setting-Scene", ...), its description (served only
 * on request by list_tags) and whether it's an adult tag. Null/false until the
 * next vocabulary sync for tags first seen on an anime.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_adult')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn(['category', 'description', 'is_adult']);
        });
    }
};
