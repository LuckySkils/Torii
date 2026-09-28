<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LinkSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A link, never a merge: deleting it leaves both the show and the anime intact.
 */
class ShowAnimeLink extends Model
{
    protected $fillable = ['show_id', 'anime_id', 'confidence', 'source', 'linked_at'];

    protected function casts(): array
    {
        return [
            'confidence' => 'integer',
            'source' => LinkSource::class,
            'linked_at' => 'datetime',
        ];
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
