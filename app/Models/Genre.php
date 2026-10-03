<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A provider genre (AniList's "Comedy", "Slice of Life", ...), shared across
 * anime. Written by the sync from the payload; `anime.genres` holds the same
 * names for filters and list rows.
 */
class Genre extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function anime(): BelongsToMany
    {
        return $this->belongsToMany(Anime::class, 'anime_genre');
    }
}
