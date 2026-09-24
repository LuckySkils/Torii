<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Release;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dashboard "latest releases" entry: the shared Release shape, plus poster and
 * novelty flags. Expects `show.image` eager-loaded (selecting only show_id/sha256).
 *
 * @mixin Release
 */
final class LatestReleaseResource extends JsonResource
{
    public function __construct(
        Release $resource,
        private readonly ?CarbonInterface $showEarliestPublishedAt = null,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $show = $this->show;

        return [
            ...(new ReleaseResource($this->resource))->toArray($request),
            'show' => $show === null ? null : [
                'id' => $show->id,
                'name' => $show->name,
                'imageUrl' => $show->image?->url(),
                'imageStatus' => $show->image_status->value,
            ],
            'isFirstEpisode' => $this->episode === '01'
                || $this->episode === '1'
                || ($this->showEarliestPublishedAt !== null && $this->published_at->equalTo($this->showEarliestPublishedAt)),
            'isNewShow' => $show !== null && $show->first_seen_at->greaterThanOrEqualTo(now()->subDays(7)),
        ];
    }
}
