<?php

declare(strict_types=1);

namespace App\Enums;

/** The only listener events the reconciler keeps (§17), whatever the source named them. */
enum ReconcilerEventType: string
{
    /** Shoko `ShokoEvent:FileMatched` / `release:saved`. */
    case FileMatched = 'file.matched';

    /** Shoko `ShokoEvent:SeriesUpdated` with `Reason: Added` / `metadata:series.added`. */
    case SeriesAdded = 'series.added';

    /** Jellyfin `LibraryChanged` touching the Anime library. */
    case LibraryChanged = 'library.changed';
}
