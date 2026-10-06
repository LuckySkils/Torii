<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which recorded reconciler events have been applied to which delivery (§17), so
 * an event is applied at most once per delivery whether it arrives live or is
 * replayed (a delivery created after its file.matched replays stored events).
 * Rows go with the event when events are pruned, or with the delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_events', function (Blueprint $table) {
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->foreignId('reconciler_event_id')->constrained('reconciler_events')->cascadeOnDelete();
            $table->timestamp('applied_at');
            $table->primary(['delivery_id', 'reconciler_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_events');
    }
};
