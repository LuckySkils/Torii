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
            $table->string('image_status')->default('none')->after('rule_error');
            $table->timestamp('image_checked_at')->nullable()->after('image_status');
            $table->text('image_error')->nullable()->after('image_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('shows', function (Blueprint $table) {
            $table->dropColumn(['image_status', 'image_checked_at', 'image_error']);
        });
    }
};
