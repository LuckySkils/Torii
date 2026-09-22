import { LatestEpisodeLabel } from '@/components/subtracker/latest-episode-label';
import { ShowPoster } from '@/components/subtracker/show-poster';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { type ShowSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';

interface ShowListRowMobileProps {
    show: ShowSummary;
}

/** Compact stacked row for the list view below `md`; the full table takes over from `md` up. */
export function ShowListRowMobile({ show }: ShowListRowMobileProps) {
    return (
        <div className="flex items-center gap-3 border-b p-2 last:border-b-0">
            <Link href={`/shows/${show.id}`} className="shrink-0">
                <ShowPoster
                    imageUrl={show.imageUrl}
                    imageStatus={show.imageStatus}
                    imageWidth={show.imageWidth}
                    imageHeight={show.imageHeight}
                    name={show.name}
                    className="w-12"
                />
            </Link>

            <Link href={`/shows/${show.id}`} className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="line-clamp-2 text-sm leading-tight font-medium">{show.name}</span>
                <span className="text-xs text-muted-foreground">
                    <LatestEpisodeLabel latest={show.latest} />
                </span>
            </Link>

            <div className="shrink-0">
                <TrackSwitch
                    showId={show.id}
                    showName={show.name}
                    tracked={show.isTracked}
                    downloadableCount={show.downloadableCount}
                    hasBatch={show.hasBatch}
                />
            </div>
        </div>
    );
}
