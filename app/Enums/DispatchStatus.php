<?php

declare(strict_types=1);

namespace App\Enums;

enum DispatchStatus: string
{
    case Sent = 'sent';
    case Exists = 'exists';
    case Error = 'error';
}
