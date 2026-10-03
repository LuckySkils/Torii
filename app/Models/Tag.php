<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A provider tag (AniList's "Isekai", "Time Skip", ...), shared across anime.
 * Written by the sync from the payload; the per-anime rank is on the pivot.
 */
class Tag extends Model
{
    public $timestamps = false;

    protected $fillable = ['name'];

    public function anime(): BelongsToMany
    {
        return $this->belongsToMany(Anime::class, 'anime_tag')->withPivot(['rank', 'is_spoiler']);
    }
}
