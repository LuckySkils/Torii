<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a release row came from. */
enum ReleaseSource: string
{
    /** SubsPlease's own RSS feed, polled by feed:poll. */
    case SubsPleaseRss = 'subsplease_rss';

    /** A Nyaa RSS link imported by hand (§16). Its pubDate is true UTC. */
    case Nyaa = 'nyaa';
}
