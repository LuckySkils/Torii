<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Show;
use Illuminate\Foundation\Events\Dispatchable;

final class ShowDiscovered
{
    use Dispatchable;

    public function __construct(
        public readonly Show $show,
    ) {}
}
