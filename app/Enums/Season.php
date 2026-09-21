<?php

declare(strict_types=1);

namespace App\Enums;

enum Season: string
{
    case Winter = 'winter';
    case Spring = 'spring';
    case Summer = 'summer';
    case Autumn = 'autumn';
}
