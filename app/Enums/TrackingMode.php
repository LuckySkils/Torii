<?php

declare(strict_types=1);

namespace App\Enums;

enum TrackingMode: string
{
    case Rule = 'rule';
    case Batch = 'batch';
}
