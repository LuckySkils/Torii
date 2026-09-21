<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageStatus;
use App\Enums\PremiereSource;
use App\Enums\RuleState;
use App\Enums\Season;
use App\Enums\TrackingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Show extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'first_seen_at',
        'last_seen_at',
        'latest_episode',
        'is_tracked',
        'tracking_mode',
        'tracked_at',
        'rule_state',
        'rule_synced_at',
        'rule_error',
        'image_status',
        'image_checked_at',
        'image_error',
        'premiered_at',
        'premiere_source',
        'season',
        'season_year',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_tracked' => 'boolean',
            'tracking_mode' => TrackingMode::class,
            'tracked_at' => 'datetime',
            'rule_state' => RuleState::class,
            'rule_synced_at' => 'datetime',
            'image_status' => ImageStatus::class,
            'image_checked_at' => 'datetime',
            'premiered_at' => 'datetime',
            'premiere_source' => PremiereSource::class,
            'season' => Season::class,
            'season_year' => 'integer',
        ];
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function latestRelease(): HasOne
    {
        return $this->hasOne(Release::class)->latestOfMany('published_at');
    }

    public function image(): HasOne
    {
        return $this->hasOne(ShowImage::class);
    }
}
