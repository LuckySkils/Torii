<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MatchRule;
use App\Enums\SuggestionReason;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate automatic matching found but wouldn't link on its own. Refreshed
 * by every matching run; cleared when the show gets linked or the pair rejected.
 */
class ShowAnimeSuggestion extends Model
{
    public $timestamps = false;

    protected $fillable = ['show_id', 'anime_id', 'score', 'rule', 'reason', 'created_at'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'rule' => MatchRule::class,
            'reason' => SuggestionReason::class,
            'created_at' => 'datetime',
        ];
    }

    public function show(): BelongsTo
    {
        return $this->belongsTo(Show::class);
    }

    public function anime(): BelongsTo
    {
        return $this->belongsTo(Anime::class);
    }
}
