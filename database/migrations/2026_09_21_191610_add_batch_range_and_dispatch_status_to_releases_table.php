<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->unsignedSmallInteger('batch_from')->nullable()->after('is_batch');
            $table->unsignedSmallInteger('batch_to')->nullable()->after('batch_from');
            $table->string('dispatch_status')->nullable()->after('dispatch_error');
        });

        DB::table('releases')->where('is_batch', true)->orderBy('id')->each(function (object $release) {
            if (preg_match('/\((\d+)-(\d+)\)/', $release->title, $matches) === 1) {
                DB::table('releases')->where('id', $release->id)->update([
                    'batch_from' => (int) $matches[1],
                    'batch_to' => (int) $matches[2],
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('releases', function (Blueprint $table) {
            $table->dropColumn(['batch_from', 'batch_to', 'dispatch_status']);
        });
    }
};
