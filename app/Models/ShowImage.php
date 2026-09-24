<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShowImage extends Model
{
    protected $hidden = ['data'];

    protected $fillable = [
        'show_id',
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

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    /**
     * Relative poster URL, versioned by hash so browsers refetch a changed image.
     * Needs only `show_id` and `sha256`; never select `data` just to build this.
     */
    public function url(): string
    {
        return route('shows.image', $this->show_id, absolute: false).'?v='.substr($this->sha256, 0, 8);
    }
}
