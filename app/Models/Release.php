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

    /**
     * Each show's highest released episode number, in one query: numbered single
     * episodes ("05", "12.5") and the end of a batch range. Shows with neither
     * (specials only, unparsed batches) are absent. Matching's episode-count gate
     * and the schedule's numbering check both read this.
     *
     * @param  array<int, int>  $showIds
     * @return array<int, float>
     */
    public static function highestEpisodes(array $showIds): array
    {
        if ($showIds === []) {
            return [];
        }

        return self::query()
            ->whereIn('show_id', $showIds)
            ->groupBy('show_id')
            ->selectRaw("show_id, max(greatest(
                case when not is_batch and episode ~ '^[0-9]+([.][0-9]+)?$' then episode::numeric end,
                case when is_batch then batch_to end
            )) as highest")
            ->get()
            ->filter(fn (self $row) => $row->getAttribute('highest') !== null)
            ->mapWithKeys(fn (self $row) => [(int) $row->show_id => (float) $row->getAttribute('highest')])
            ->all();
    }
}
