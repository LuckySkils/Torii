<?php

declare(strict_types=1);

use App\Services\Feed\Infohash;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('releases')->whereNotNull('infohash')->orderBy('id')->each(function (object $release) {
            $normalized = Infohash::normalize($release->infohash);

            if ($normalized !== null && $normalized !== $release->infohash) {
                DB::table('releases')->where('id', $release->id)->update(['infohash' => $normalized]);
            }
        });
    }

    public function down(): void
    {
        // Normalization is one-directional; the original base32 form isn't recoverable from hex alone.
    }
};
