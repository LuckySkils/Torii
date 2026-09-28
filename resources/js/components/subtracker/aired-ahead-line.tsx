import { airedAhead, airedAheadLabel, latestEpisodeNumber } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type AnimeLinkSummary, type LatestRelease } from '@/types/subtracker';
import { Clock } from 'lucide-react';

interface AiredAheadLineProps {
    show: { isTracked: boolean; latest?: LatestRelease | null; anime: AnimeLinkSummary | null };
    className?: string;
}

/** List-row version of the show page's "Ep N aired, not released yet" badge: same rule, quiet text instead of a badge. */
export function AiredAheadLine({ show, className }: AiredAheadLineProps) {
    const aired = airedAhead(show.isTracked, show.anime?.episodesAired, latestEpisodeNumber(show.latest));

    if (!aired) {
        return null;
    }

    return (
        <span className={cn('inline-flex items-center gap-1 text-xs text-sky-700 dark:text-sky-300', className)}>
            <Clock className="size-3 shrink-0" aria-hidden />
            <span className="truncate">{airedAheadLabel(aired)}</span>
        </span>
    );
}
