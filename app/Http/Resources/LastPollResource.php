<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeedPoll;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FeedPoll
 */
final class LastPollResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'at' => $this->started_at->toIso8601String(),
            'status' => $this->http_status,
            'notModified' => $this->not_modified,
            'itemsNew' => $this->items_new,
            'error' => $this->error,
        ];
    }
}
