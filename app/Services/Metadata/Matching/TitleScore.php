<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\MatchRule;

final readonly class TitleScore
{
    public function __construct(
        public int $score,
        public ?MatchRule $rule,
    ) {}
}
