<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Show;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Show
 */
final class ShowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'latestEpisode' => $this->latest_episode,
            'firstSeenAt' => $this->first_seen_at->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at->toIso8601String(),
            'isTracked' => $this->is_tracked,
            'ruleState' => $this->rule_state->value,
            'ruleError' => $this->rule_error,
            'latestRelease' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease === null ? null : [
                'title' => $this->latestRelease->title,
                'publishedAt' => $this->latestRelease->published_at->toIso8601String(),
                'link' => $this->latestRelease->link,
            ]),
        ];
    }
}
