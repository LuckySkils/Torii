<?php

declare(strict_types=1);

namespace App\Services\Reconciler;

use App\Enums\ReconcilerEventType;
use App\Jobs\Reconciler\ProcessReconcilerEvent;
use App\Models\ReconcilerEvent;

/**
 * The listener's only write (§17): keep the event, hand it to the worker. Every
 * decision happens in ProcessReconcilerEvent, so the listener stays restart-safe
 * and the logic can be replayed from recorded events.
 */
final class EventRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $source, ReconcilerEventType $type, string $rawTarget, array $payload): ReconcilerEvent
    {
        $event = ReconcilerEvent::create([
            'source' => $source,
            'type' => $type,
            'raw_target' => $rawTarget,
            'payload' => $payload,
            'received_at' => now(),
        ]);

        ProcessReconcilerEvent::dispatch($event->id);

        return $event;
    }
}
