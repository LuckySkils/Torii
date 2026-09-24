import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDelay } from '@/lib/dates';
import { type DashboardRelease } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { CopyLinkButton } from './copy-link-button';
import { DispatchBadge } from './dispatch-badge';
import { DownloadButton } from './download-button';
import { LatestEpisodeLabel } from './latest-episode-label';
import { FirstEpisodeBadge, isPremiere, NewShowBadge } from './novelty-badges';
import { PosterHoverPreview } from './poster-hover-preview';
import { RelativeTime } from './relative-time';
import { ShowPoster } from './show-poster';

interface LatestReleasesTableProps {
    releases: DashboardRelease[];
}

export function ReleaseThumb({ release, className = 'w-10' }: { release: DashboardRelease; className?: string }) {
    if (release.show === null) {
        return <div className={`${className} aspect-[2/3] shrink-0 rounded-md bg-muted`} />;
    }

    return (
        <Link href={`/shows/${release.show.id}`} className="block shrink-0" tabIndex={-1} aria-hidden>
            <ShowPoster imageUrl={release.show.imageUrl} imageStatus={release.show.imageStatus} name={release.show.name} className={className} />
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
                        <TableHead className="w-14">
                            <span className="sr-only">Poster</span>
                        </TableHead>
                        <TableHead>Show</TableHead>
                        <TableHead>Episode</TableHead>
                        <TableHead>Title</TableHead>
                        <TableHead>Published</TableHead>
                        <TableHead>First seen</TableHead>
                        <TableHead>Delay</TableHead>
                        <TableHead>Status</TableHead>
                        <TableHead className="w-20" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {releases.map((release) => (
                        <TableRow key={release.id}>
                            <TableCell className="py-2">
                                {release.show ? (
                                    <PosterHoverPreview
                                        imageUrl={release.show.imageUrl}
                                        imageStatus={release.show.imageStatus}
                                        name={release.show.name}
                                        thumbClassName="w-10"
                                        href={`/shows/${release.show.id}`}
                                    />
                                ) : (
                                    <ReleaseThumb release={release} />
                                )}
                            </TableCell>
                            <TableCell className="max-w-56">
                                {release.show ? (
                                    <div className="flex flex-col items-start gap-1">
                                        <Link href={`/shows/${release.show.id}`} className="line-clamp-2 font-medium hover:underline">
                                            {release.show.name}
                                        </Link>
                                        {release.isNewShow && <NewShowBadge />}
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
                            <TableCell className="max-w-xs truncate text-muted-foreground" title={release.title}>
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
