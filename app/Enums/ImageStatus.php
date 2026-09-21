<?php

declare(strict_types=1);

namespace App\Enums;

enum ImageStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case Found = 'found';
    case Missing = 'missing';
    case Error = 'error';
}
