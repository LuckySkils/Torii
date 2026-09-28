<?php

declare(strict_types=1);

namespace App\Enums;

/** Why automatic matching suggested a candidate instead of linking it. */
enum SuggestionReason: string
{
    /** Another candidate scored within 10 points. */
    case Ambiguous = 'ambiguous';

    /**
     * The candidate has fewer episodes than the show has released (beyond a
     * tolerance for specials): the wrong season, or SubsPlease numbering
     * episodes on from season 1 (Hyakkano at 36 against a 12-episode entry).
     */
    case EpisodeCount = 'episode_count';

    /** The user has rejected a pair for this show, so it's never auto-linked again. */
    case ShowHasRejection = 'show_has_rejection';
}
