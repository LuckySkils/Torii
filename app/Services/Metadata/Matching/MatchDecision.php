<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

use App\Enums\SuggestionReason;
use App\Models\Show;

final readonly class MatchDecision
{
    /**
     * @param  array<int, ScoredCandidate>  $candidates  every candidate scoring >= 70, best first
     * @param  array<int, ScoredCandidate>  $suggestions  for an ambiguous or held decision: what to suggest instead
     */
    public function __construct(
        public Show $show,
        public MatchOutcome $outcome,
        public ?ScoredCandidate $link,
        public array $candidates,
        public array $suggestions = [],
        public ?SuggestionReason $reason = null,
    ) {}

    public function best(): ?ScoredCandidate
    {
        return $this->candidates[0] ?? null;
    }

    public function runnerUp(): ?ScoredCandidate
    {
        return $this->candidates[1] ?? null;
    }
}
