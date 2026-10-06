<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a downloaded episode is on its way to being playable in Jellyfin (§17). */
enum DeliveryState: string
{
    /** Torii marked the release downloaded; Shoko hasn't matched the file yet. */
    case Downloaded = 'downloaded';

    /** Shoko matched the file to an existing series. */
    case Matched = 'matched';

    /** Shoko matched the file to a show it has no series for yet (a new show). */
    case AwaitingSeries = 'awaiting_series';

    /** Jellyfin has the episode. */
    case InJellyfin = 'in_jellyfin';

    /** The Anime library refresh (fix A) is running for it. */
    case FixingA = 'fixing_a';

    /** Its series was refreshed (fix B); being re-checked. */
    case FixingB = 'fixing_b';

    /** Its series lists episodes: it can be played. */
    case Playable = 'playable';

    /** A fix didn't help; a manual Scan All Libraries is needed. */
    case GaveUp = 'gave_up';
}
