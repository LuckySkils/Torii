<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReconcilerEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A Shoko or Jellyfin event the listener kept (§17), normalized to one of the
 * reconciler's types with the raw argument as payload. Pruned after 14 days.
 */
class ReconcilerEvent extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['source', 'type', 'raw_target', 'payload', 'received_at', 'processed_at'];

    protected function casts(): array
    {
        return [
            'type' => ReconcilerEventType::class,
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::where('received_at', '<', now()->subDays((int) config('subtracker.reconciler.event_retention_days')));
    }
}
