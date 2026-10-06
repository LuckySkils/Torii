<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DeliveryState;
use App\Models\Delivery;
use App\Services\Reconciler\DeliveryReconciler;
use Illuminate\Console\Command;

/**
 * Applies the stored reconciler events (§17) to existing deliveries: for one
 * created before Torii replayed events on creation, or any delivery that missed
 * an event. Idempotent: events already applied are skipped. Checks it starts run
 * on the worker, with dry run as configured.
 */
class ReconcilerReplay extends Command
{
    protected $signature = 'reconciler:replay
        {delivery?* : Delivery ids}
        {--downloaded : Every delivery still at "downloaded"}';

    protected $description = 'Re-run deliveries against the stored Shoko events they missed';

    public function handle(DeliveryReconciler $reconciler): int
    {
        $ids = array_map('intval', (array) $this->argument('delivery'));

        if ($ids === [] && ! $this->option('downloaded')) {
            $this->error('Name delivery ids, or pass --downloaded.');

            return self::FAILURE;
        }

        $deliveries = Delivery::query()
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->when($this->option('downloaded'), fn ($query) => $query->orWhere('state', DeliveryState::Downloaded))
            ->orderBy('id')
            ->get();

        if ($missing = array_diff($ids, $deliveries->modelKeys())) {
            $this->warn('No delivery with id '.implode(', ', $missing).'.');
        }

        $rows = $deliveries->map(fn (Delivery $delivery) => [
            $delivery->id,
            $delivery->filename,
            $reconciler->replayStoredEvents($delivery),
            $delivery->refresh()->state->value,
        ]);

        $this->table(['Delivery', 'File', 'Events applied', 'State now'], $rows->all());

        if (config('subtracker.reconciler.dry_run')) {
            $this->line('Dry run: any fix the checks decide on is recorded, not sent.');
        }

        return self::SUCCESS;
    }
}
