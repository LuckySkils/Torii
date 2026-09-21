<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('releases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->nullable()->constrained('shows')->nullOnDelete();
            $table->string('guid')->unique();
            $table->string('title');
            $table->string('episode')->nullable();
            $table->unsignedTinyInteger('version')->nullable();
            $table->boolean('is_batch');
            $table->string('resolution');
            $table->string('crc')->nullable();
            $table->text('link');
            $table->string('infohash')->nullable();
            $table->string('size_label')->nullable();
            $table->timestamp('published_at');
            $table->timestamp('first_seen_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->text('dispatch_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
    }
};
