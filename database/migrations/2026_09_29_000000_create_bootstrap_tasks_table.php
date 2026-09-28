<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per one-time data task (§12). A task runs once ever; a task
        // added in a later release runs on the next start of any install.
        Schema::create('bootstrap_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bootstrap_tasks');
    }
};
