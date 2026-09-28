<?php

declare(strict_types=1);

namespace App\Enums;

/** Which matching rule produced a score (§9.5, plus the subtitle rule). */
enum MatchRule: string
{
    /** Normalized titles equal (ignoring spaces): 100. */
    case Exact = 'exact';

    /** The part of a title before its first colon equals the show name: 95. */
    case SubtitlePrefix = 'subtitle_prefix';

    /** Same base and season once markers are unified (S2 / 2nd Season): 90. */
    case SeasonMarker = 'season_marker';

    /** Same season, Levenshtein ratio >= 0.9: 70–85. */
    case Similarity = 'similarity';
}
