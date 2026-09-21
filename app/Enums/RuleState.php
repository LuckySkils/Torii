<?php

declare(strict_types=1);

namespace App\Enums;

enum RuleState: string
{
    case None = 'none';
    case Pending = 'pending';
    case Synced = 'synced';
    case Disabled = 'disabled';
    case Error = 'error';
}
