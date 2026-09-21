<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\DispatchStatus;
use App\Models\Show;
use App\Services\Downloads\DownloadPlanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Show
 */
final class ShowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $image = $this->resource->image()->first(['id', 'sha256']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'latestEpisode' => $this->latest_episode,
            'firstSeenAt' => $this->first_seen_at->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at->toIso8601String(),
            'isTracked' => $this->is_tracked,
            'trackingMode' => $this->tracking_mode?->value,
            'ruleState' => $this->rule_state->value,
            'ruleError' => $this->rule_error,
            'latestRelease' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease === null ? null : [
                'title' => $this->latestRelease->title,
                'publishedAt' => $this->latestRelease->published_at->toIso8601String(),
                'link' => $this->latestRelease->link,
            ]),
            'hasBatch' => $this->releases()->where('is_batch', true)->exists(),
            'queuedCount' => $this->releases()->whereIn('dispatch_status', [DispatchStatus::Sent, DispatchStatus::Exists])->count(),
            'downloadableCount' => app(DownloadPlanner::class)->downloadableSet($this->resource)->count(),
            'imageUrl' => $image === null ? null : route('shows.image', $this->id, absolute: false).'?v='.substr($image->sha256, 0, 8),
            'imageStatus' => $this->image_status->value,
            'imageCheckedAt' => $this->image_checked_at?->toIso8601String(),
            'imageError' => $this->image_error,
        ];
    }
}
