<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Candidates automatic matching found but wouldn't link on its own (ambiguous).
        Schema::create('show_anime_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained('shows')->cascadeOnDelete();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('rule');
            $table->timestamp('created_at');

            $table->unique(['show_id', 'anime_id']);
        });

        // Pairs the user said are wrong: never auto-linked or suggested again.
        Schema::create('show_anime_rejections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained('shows')->cascadeOnDelete();
            $table->foreignId('anime_id')->constrained('anime')->cascadeOnDelete();
            $table->timestamp('rejected_at');

            $table->unique(['show_id', 'anime_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_anime_rejections');
        Schema::dropIfExists('show_anime_suggestions');
    }
};
