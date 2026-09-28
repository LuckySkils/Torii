<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\MatchRule;

final readonly class ScoredCandidate
{
    public function __construct(
        public MatchCandidate $candidate,
        public int $score,
        public MatchRule $rule,
    ) {}
}
