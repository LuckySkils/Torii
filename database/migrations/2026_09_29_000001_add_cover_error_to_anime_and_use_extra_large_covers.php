<?php

declare(strict_types=1);

use App\Services\Metadata\CoverUrls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A cover download that failed for good: on-demand fetching skips these
        // (anime:fetch-images still retries them).
        Schema::table('anime', function (Blueprint $table) {
            $table->text('cover_error')->nullable()->after('cover_url');
            $table->timestamp('cover_error_at')->nullable()->after('cover_error');
        });

        // Point every cover URL at AniList's largest size, read from the payload we
        // already store: no provider requests. A stored cover whose source_url then
        // differs from cover_url is the old size; `anime:fetch-images --upgrade`
        // replaces it.
        app(CoverUrls::class)->useLargest();
    }

    public function down(): void
    {
        // The URL rewrite isn't undone: the next season sync sets whatever the parser picks.
        Schema::table('anime', function (Blueprint $table) {
            $table->dropColumn(['cover_error', 'cover_error_at']);
        });
    }
};
