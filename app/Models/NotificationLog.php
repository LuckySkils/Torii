<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationKind;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationLog extends Model
{
    protected $table = 'notification_log';

    protected $fillable = [
        'kind',
        'release_id',
        'status',
        'error',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => NotificationKind::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }
}
