<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Show;
use RuntimeException;

/**
 * TrackShow refused: the show has no SubsPlease release, so a qBittorrent rule
 * (which follows SubsPlease's feed) would never match anything (§16). The
 * message is the reason, ready to show the user.
 */
final class ShowNotTrackable extends RuntimeException
{
    public function __construct(public readonly Show $show, string $reason)
    {
        parent::__construct($reason);
    }
}
