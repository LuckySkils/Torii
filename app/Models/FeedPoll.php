<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedPoll extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'started_at',
        'finished_at',
        'http_status',
        'not_modified',
        'items_total',
        'items_new',
        'shows_new',
        'error',
        'etag',
        'last_modified',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'not_modified' => 'boolean',
        ];
    }
}
