<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeAiring extends Model
{
    protected $fillable = ['anime_id', 'provider', 'episode', 'airs_at', 'is_estimate'];

    protected function casts(): array
    {
        return [
            'episode' => 'integer',
            'airs_at' => 'datetime',
            'is_estimate' => 'boolean',
        ];
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
