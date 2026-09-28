<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Stored exactly like ShowImage: base64 in a text column, never selected in lists.
 */
class AnimeImage extends Model
{
    protected $hidden = ['data'];

    protected $fillable = [
        'anime_id',
        'source_url',
        'mime',
        'data',
        'size',
        'width',
        'height',
        'sha256',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'fetched_at' => 'datetime',
        ];
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }

    /** Needs only `anime_id` and `sha256`. */
    public function url(): string
    {
        return route('anime.cover', $this->anime_id, absolute: false).'?v='.substr($this->sha256, 0, 8);
    }
}
