<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_polls', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('not_modified')->default(false);
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_new')->default(0);
            $table->unsignedInteger('shows_new')->default(0);
            $table->text('error')->nullable();
            $table->string('etag')->nullable();
            $table->string('last_modified')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_polls');
    }
};
