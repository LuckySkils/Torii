<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Why matching suggested instead of linking (App\Enums\SuggestionReason).
        // Existing rows all came from the ambiguity rule.
        Schema::table('show_anime_suggestions', function (Blueprint $table) {
            $table->string('reason')->default('ambiguous')->after('rule');
        });
    }

    public function down(): void
    {
        Schema::table('show_anime_suggestions', function (Blueprint $table) {
            $table->dropColumn('reason');
        });
    }
};
