<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Release;
use Illuminate\Foundation\Events\Dispatchable;

final class ReleaseDownloaded
{
    use Dispatchable;

    public function __construct(
        public readonly Release $release,
    ) {}
}
