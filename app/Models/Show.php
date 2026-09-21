<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RuleState;
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
        'tracked_at',
        'rule_state',
        'rule_synced_at',
        'rule_error',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_tracked' => 'boolean',
            'tracked_at' => 'datetime',
            'rule_state' => RuleState::class,
            'rule_synced_at' => 'datetime',
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
}
