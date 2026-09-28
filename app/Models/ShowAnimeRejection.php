<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A show/anime pair the user said is wrong (by unlinking it or rejecting the
 * suggestion). Automatic matching never links or suggests it again; linking the
 * pair manually removes the rejection.
 */
class ShowAnimeRejection extends Model
{
    public $timestamps = false;

    protected $fillable = ['show_id', 'anime_id', 'rejected_at'];

    protected function casts(): array
    {
        return ['rejected_at' => 'datetime'];
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
