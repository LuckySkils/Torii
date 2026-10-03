<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * An animation studio (AniList's main studios only), shared across anime.
 * Written by the sync from the payload.
 */
class Studio extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function anime(): BelongsToMany
    {
        return $this->belongsToMany(Anime::class, 'anime_studio');
    }
}
