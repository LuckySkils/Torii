<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DispatchStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Release extends Model
{
    protected $fillable = [
        'show_id',
        'guid',
        'title',
        'episode',
        'version',
        'is_batch',
        'batch_from',
        'batch_to',
        'resolution',
        'crc',
        'link',
        'infohash',
        'size_label',
        'published_at',
        'published_at_raw',
        'first_seen_at',
        'dispatched_at',
        'dispatch_error',
        'dispatch_status',
        'downloaded_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_batch' => 'boolean',
            'batch_from' => 'integer',
            'batch_to' => 'integer',
            'published_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'dispatch_status' => DispatchStatus::class,
            'downloaded_at' => 'datetime',
        ];
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }
}
