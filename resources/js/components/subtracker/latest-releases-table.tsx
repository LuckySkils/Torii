import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { latestEpisodeNumber, posterFor } from '@/lib/anime';
import { formatDelay } from '@/lib/dates';
import { COLUMN_HINTS } from '@/lib/hints';
import { ENTRY_COVER } from '@/lib/utils';
import { type DashboardRelease } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { type ReactNode } from 'react';
import { AnimeCardCover, AnimeDetailCard } from './anime-detail-card';
import { AnimeLinkIndicator } from './anime-link-indicator';
import { CopyLinkButton } from './copy-link-button';
import { DispatchBadge } from './dispatch-badge';
import { DownloadButton } from './download-button';
import { ColumnHint } from './hint';
import { LatestEpisodeLabel } from './latest-episode-label';
import { FirstEpisodeBadge, isPremiere, NewShowBadge } from './novelty-badges';
import { PosterHoverPreview } from './poster-hover-preview';
import { RelativeTime } from './relative-time';
import { ShowPoster } from './show-poster';
import { TrackSwitch } from './track-switch';

interface LatestReleasesTableProps {
    releases: DashboardRelease[];
}

/** A release's own markers, repeated inside its detail card. */
function releaseBadges(release: DashboardRelease): ReactNode {
    const premiere = isPremiere(release);

    if (!release.isNewShow && !premiere) {
        return undefined;
    }

    return (
        <span className="flex flex-wrap items-center gap-1">
            {release.isNewShow && <NewShowBadge />}
            {premiere && <FirstEpisodeBadge />}
        </span>
    );
}

/**
 * The release's poster, linking to its show. When the show is linked to an anime,
 * hovering (or, on touch, tapping) it opens the anime's detail card; otherwise the
 * table keeps its enlarged-poster preview (`preview`) and the phone card a plain link.
 */
export function ReleaseThumb({ release, className = 'w-10', preview = false }: { release: DashboardRelease; className?: string; preview?: boolean }) {
    const show = release.show;

    if (show === null) {
        return <div className={`${className} aspect-[2/3] shrink-0 rounded-md bg-muted`} />;
    }

    if (show.anime) {
        return (
            <AnimeDetailCard animeId={show.anime.id} badges={releaseBadges(release)}>
                <span className="block shrink-0">
                    <AnimeCardCover>
                        <Link href={`/shows/${show.id}`} className="block" aria-label={`${show.name}: details`}>
                            <ShowPoster {...posterFor(show)} name={show.name} className={className} />
                        </Link>
                    </AnimeCardCover>
                </span>
            </AnimeDetailCard>
        );
    }

    if (preview) {
        return <PosterHoverPreview {...posterFor(show)} name={show.name} thumbClassName={className} href={`/shows/${show.id}`} />;
    }

    return (
        <Link href={`/shows/${show.id}`} className="block shrink-0" tabIndex={-1} aria-hidden>
            <ShowPoster {...posterFor(show)} name={show.name} className={className} />
        </Link>
    );
}

export function ReleaseEpisode({ release }: { release: DashboardRelease }) {
    return (
        <span className="inline-flex items-center gap-1 whitespace-nowrap">
            <LatestEpisodeLabel latest={release} />
            {release.version != null && release.version > 1 && (
                <Badge variant="outline" className="font-normal">
                    v{release.version}
                </Badge>
            )}
        </span>
    );
}

/** Dashboard list. Kept in the backend's order (newest published first) — deliberately not re-sorted here. */
export function LatestReleasesTable({ releases }: LatestReleasesTableProps) {
    return (
        <div className="overflow-x-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-24 large:md:w-28">
                            <span className="sr-only">Poster</span>
                        </TableHead>
                        <TableHead>Show</TableHead>
                        <TableHead>Episode</TableHead>
                        <TableHead>Title</TableHead>
                        <TableHead>
                            <ColumnHint label="Published">{COLUMN_HINTS.published}</ColumnHint>
                        </TableHead>
                        <TableHead>
                            <ColumnHint label="First seen">{COLUMN_HINTS.firstSeen}</ColumnHint>
                        </TableHead>
                        <TableHead>
                            <ColumnHint label="Delay">{COLUMN_HINTS.delay}</ColumnHint>
                        </TableHead>
                        <TableHead>
                            <ColumnHint label="Track">{COLUMN_HINTS.track}</ColumnHint>
                        </TableHead>
                        <TableHead>
                            <ColumnHint label="Status">{COLUMN_HINTS.status}</ColumnHint>
                        </TableHead>
                        <TableHead className="w-20" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {releases.map((release) => (
                        <TableRow key={release.id}>
                            <TableCell className="py-2">
                                <ReleaseThumb release={release} className={ENTRY_COVER} preview />
                            </TableCell>
                            <TableCell className="max-w-44 2xl:max-w-56">
                                {release.show ? (
                                    <div className="flex flex-col items-start gap-1">
                                        <Link href={`/shows/${release.show.id}`} className="line-clamp-2 font-medium hover:underline">
                                            {release.show.name}
                                        </Link>
                                        <span className="flex flex-wrap items-center gap-1">
                                            {release.isNewShow && <NewShowBadge />}
                                            <AnimeLinkIndicator show={release.show} currentEpisode={latestEpisodeNumber(release)} />
                                        </span>
                                    </div>
                                ) : (
                                    <Badge variant="outline">unparsed</Badge>
                                )}
                            </TableCell>
                            <TableCell>
                                <div className="flex flex-col items-start gap-1">
                                    <ReleaseEpisode release={release} />
                                    {isPremiere(release) && <FirstEpisodeBadge />}
                                </div>
                            </TableCell>
                            <TableCell
                                className="max-w-48 truncate text-muted-foreground 2xl:max-w-sm large:md:max-w-40 large:2xl:max-w-sm"
                                title={release.title}
                            >
                                <a href={release.link} className="hover:underline">
                                    {release.title}
                                </a>
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                <RelativeTime iso={release.publishedAt} />
                            </TableCell>
                            <TableCell className="whitespace-nowrap">
                                <RelativeTime iso={release.firstSeenAt} />
                            </TableCell>
                            <TableCell className="whitespace-nowrap">{formatDelay(release.publishedAt, release.firstSeenAt)}</TableCell>
                            <TableCell>
                                {release.show ? (
                                    <TrackSwitch showId={release.show.id} showName={release.show.name} tracked={release.show.isTracked} />
                                ) : (
                                    <span className="text-sm text-muted-foreground">—</span>
                                )}
                            </TableCell>
                            <TableCell>
                                <DispatchBadge
                                    status={release.dispatchStatus}
                                    dispatchedAt={release.dispatchedAt}
                                    error={release.dispatchError}
                                    downloadedAt={release.downloadedAt}
                                />
                            </TableCell>
                            <TableCell>
                                <div className="flex items-center gap-1">
                                    {release.downloadedAt === null && release.dispatchStatus !== 'sent' && release.dispatchStatus !== 'exists' && (
                                        <DownloadButton releaseId={release.id} title={release.title} retry={release.dispatchStatus === 'error'} />
                                    )}
                                    <CopyLinkButton link={release.link} label={`Copy link for ${release.title}`} />
                                </div>
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
