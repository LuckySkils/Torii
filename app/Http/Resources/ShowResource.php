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
        $image = $this->resource->image()->first(['id', 'sha256', 'width', 'height']);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'firstSeenAt' => $this->first_seen_at->toIso8601String(),
            'lastSeenAt' => $this->last_seen_at->toIso8601String(),
            'isTracked' => $this->is_tracked,
            'trackingMode' => $this->tracking_mode?->value,
            'ruleState' => $this->rule_state->value,
            'ruleError' => $this->rule_error,
            'latest' => $this->whenLoaded('latestRelease', fn () => $this->latestRelease === null ? null : [
                'episode' => $this->latestRelease->episode,
                'isBatch' => $this->latestRelease->is_batch,
                'batchFrom' => $this->latestRelease->batch_from,
                'batchTo' => $this->latestRelease->batch_to,
                'publishedAt' => $this->latestRelease->published_at->toIso8601String(),
            ]),
            'hasBatch' => $this->releases()->where('is_batch', true)->exists(),
            'queuedCount' => $this->releases()->whereIn('dispatch_status', [DispatchStatus::Sent, DispatchStatus::Exists])->count(),
            'downloadableCount' => app(DownloadPlanner::class)->downloadableSet($this->resource)->count(),
            'downloadedCount' => $this->releases()->whereNotNull('downloaded_at')->count(),
            'imageUrl' => $image === null ? null : route('shows.image', $this->id, absolute: false).'?v='.substr($image->sha256, 0, 8),
            'imageStatus' => $this->image_status->value,
            'imageCheckedAt' => $this->image_checked_at?->toIso8601String(),
            'imageError' => $this->image_error,
            'imageWidth' => $image?->width,
            'imageHeight' => $image?->height,
            'season' => $this->season?->value,
            'seasonYear' => $this->season_year,
            'premieredAt' => $this->premiered_at?->toIso8601String(),
            'premiereSource' => $this->premiere_source?->value,
        ];
    }
}
