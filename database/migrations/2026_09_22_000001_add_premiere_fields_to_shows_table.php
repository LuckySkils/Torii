<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->timestamp('premiered_at')->nullable()->after('image_error');
            $table->string('premiere_source')->nullable()->after('premiered_at');
            $table->string('season')->nullable()->after('premiere_source');
            $table->smallInteger('season_year')->nullable()->after('season');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['premiered_at', 'premiere_source', 'season', 'season_year']);
        });
    }
};
