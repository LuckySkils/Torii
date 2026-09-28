<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnimeExternalId extends Model
{
    protected $fillable = ['anime_id', 'provider', 'external_id'];

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
