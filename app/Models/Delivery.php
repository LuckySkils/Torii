<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeliveryState;
use App\Enums\FixAttempted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One downloaded episode on its way to being playable in Jellyfin (§17).
 */
class Delivery extends Model
{
    protected $fillable = [
        'release_id',
        'filename',
        'shoko_file_id',
        'anidb_anime_id',
        'shoko_series_id',
        'is_new_show',
        'jellyfin_episode_id',
        'jellyfin_series_id',
        'state',
        'fix_attempted',
        'would_have_fixed',
        'last_error',
        'run',
        'matched_at',
        'in_jellyfin_at',
        'playable_at',
        'gave_up_at',
    ];

    protected $attributes = [
        'state' => 'downloaded',
        'fix_attempted' => 'none',
        'is_new_show' => false,
        'run' => 1,
    ];

    protected function casts(): array
    {
        return [
            'shoko_file_id' => 'integer',
            'anidb_anime_id' => 'integer',
            'shoko_series_id' => 'integer',
            'is_new_show' => 'boolean',
            'state' => DeliveryState::class,
            'fix_attempted' => FixAttempted::class,
            'run' => 'integer',
            'matched_at' => 'datetime',
            'in_jellyfin_at' => 'datetime',
            'playable_at' => 'datetime',
            'gave_up_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * The frontend's `delivery` prop (§17).
     *
     * @return array<string, mixed>
     */
    public function toProps(): array
    {
        return [
            'state' => $this->state->value,
            'isNewShow' => $this->is_new_show,
            'fixAttempted' => $this->fix_attempted->value,
            'wouldHaveFixed' => $this->would_have_fixed,
            'lastError' => $this->last_error,
            'stages' => [
                'matchedAt' => $this->matched_at?->toIso8601String(),
                'inJellyfinAt' => $this->in_jellyfin_at?->toIso8601String(),
                'playableAt' => $this->playable_at?->toIso8601String(),
                'gaveUpAt' => $this->gave_up_at?->toIso8601String(),
            ],
        ];
    }
}
