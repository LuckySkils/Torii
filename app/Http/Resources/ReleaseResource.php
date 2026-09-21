<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Release;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Release
 */
final class ReleaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'show' => $this->show === null ? null : [
                'id' => $this->show->id,
                'name' => $this->show->name,
            ],
            'episode' => $this->episode,
            'version' => $this->version,
            'isBatch' => $this->is_batch,
            'batchFrom' => $this->batch_from,
            'batchTo' => $this->batch_to,
            'publishedAt' => $this->published_at->toIso8601String(),
            'firstSeenAt' => $this->first_seen_at->toIso8601String(),
            'link' => $this->link,
            'dispatchStatus' => $this->dispatch_status?->value,
            'dispatchedAt' => $this->dispatched_at?->toIso8601String(),
            'dispatchError' => $this->dispatch_error,
        ];
    }
}
