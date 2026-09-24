<?php

declare(strict_types=1);

use App\Services\Feed\PublishedAtFixer;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->string('published_at_raw')->nullable()->after('published_at');
        });

        // Correct existing rows in place; a no-op on a fresh install.
        app(PublishedAtFixer::class)->run();
    }

    public function down(): void
    {
        // Restore the literal feed values the pre-fix code expects.
        DB::table('releases')->whereNotNull('published_at_raw')->orderBy('id')->each(function (object $release) {
            DB::table('releases')->where('id', $release->id)->update([
                'published_at' => Carbon::parse($release->published_at_raw)->utc()->toDateTimeString(),
            ]);
        });

        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn('published_at_raw');
        });
    }
};
