<?php

declare(strict_types=1);

namespace App\Services\Metadata\Matching;

enum MatchOutcome: string
{
    /** Scored >= 90 with no runner-up within 10: auto-link. */
    case Link = 'link';

    /** Scored >= 90, but a runner-up is within 10: nothing linked, the close ones become suggestions. */
    case Ambiguous = 'ambiguous';

    /**
     * Would have auto-linked, but a gate stopped it (the candidate has too few
     * episodes, or the show has a rejection): the candidate becomes a suggestion.
     */
    case Held = 'held';

    /** Best candidate scored 70–89: left for manual linking. */
    case Weak = 'weak';

    /** Nothing scored at all. */
    case None = 'none';
}
