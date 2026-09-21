import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDelay } from '@/lib/dates';
import { type ReleaseSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { CopyLinkButton } from './copy-link-button';
import { RelativeTime } from './relative-time';

interface ReleasesTableProps {
    releases: ReleaseSummary[];
    showColumn?: boolean;
    versionColumn?: boolean;
    emptyMessage?: string;
}

export function ReleasesTable({ releases, showColumn = false, versionColumn = false, emptyMessage = 'No releases yet.' }: ReleasesTableProps) {
    if (releases.length === 0) {
        return <p className="p-4 text-sm text-muted-foreground">{emptyMessage}</p>;
    }

    return (
        <div className="overflow-x-auto rounded-xl border">
            <Table>
                <TableHeader>
                    <TableRow>
                        {versionColumn && <TableHead>Episode</TableHead>}
                        <TableHead>Title</TableHead>
                        {showColumn && <TableHead>Show</TableHead>}
                        {!versionColumn && <TableHead>Episode</TableHead>}
                        <TableHead>Published</TableHead>
                        <TableHead>First seen</TableHead>
                        <TableHead>Delay</TableHead>
                        <TableHead className="w-10" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {releases.map((release) => (
                        <TableRow key={release.id}>
                            {versionColumn && (
                                <TableCell className="whitespace-nowrap">
                                    {release.episode ?? '—'}
                                    {release.version != null && release.version > 1 && (
                                        <Badge variant="outline" className="ml-1">
                                            v{release.version}
                                        </Badge>
                                    )}
                                    {release.isBatch && (
                                        <Badge variant="secondary" className="ml-1">
                                            batch
                                        </Badge>
                                    )}
                                </TableCell>
                            )}
                            <TableCell className="max-w-sm truncate" title={release.title}>
                                <a href={release.link} className="hover:underline">
                                    {release.title}
                                </a>
                            </TableCell>
                            {showColumn && (
                                <TableCell>
                                    {release.show ? (
                                        <Link href={`/shows/${release.show.id}`} className="hover:underline">
                                            {release.show.name}
                                        </Link>
                                    ) : (
                                        <Badge variant="outline">unparsed</Badge>
                                    )}
                                </TableCell>
                            )}
                            {!versionColumn && <TableCell>{release.episode ?? '—'}</TableCell>}
                            <TableCell>
                                <RelativeTime iso={release.publishedAt} />
                            </TableCell>
                            <TableCell>
                                <RelativeTime iso={release.firstSeenAt} />
                            </TableCell>
                            <TableCell>{formatDelay(release.publishedAt, release.firstSeenAt)}</TableCell>
                            <TableCell>
                                <CopyLinkButton link={release.link} label={`Copy link for ${release.title}`} />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}
