import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDelay } from '@/lib/dates';
import { TOUCH_TARGET_SM } from '@/lib/utils';
import { type ReleaseSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { toast } from 'sonner';
import { DispatchBadge } from './dispatch-badge';
import { DownloadButton } from './download-button';
import { RelativeTime } from './relative-time';

interface ReleaseCardProps {
    release: ReleaseSummary;
    showLink?: boolean;
}

function episodeLabel(release: ReleaseSummary): string {
    if (release.isBatch) {
        if (release.batchFrom != null && release.batchTo != null) {
            return `${String(release.batchFrom).padStart(2, '0')}–${String(release.batchTo).padStart(2, '0')}`;
        }

        return 'Batch';
    }

    return release.episode ?? '—';
}

async function copyLink(link: string) {
    try {
        await navigator.clipboard.writeText(link);
        toast.success('Link copied to clipboard');
    } catch {
        toast.error("Couldn't copy the link");
    }
}

export function ReleaseCard({ release, showLink = false }: ReleaseCardProps) {
    const canDownload = release.downloadedAt === null && release.dispatchStatus !== 'sent' && release.dispatchStatus !== 'exists';

    return (
        <div className="flex flex-col gap-1.5 rounded-lg border p-3">
            <div className="flex items-center justify-between gap-2">
                <span className="flex items-center gap-1 text-sm font-medium">
                    {episodeLabel(release)}
                    {release.version != null && release.version > 1 && (
                        <Badge variant="outline" className="font-normal">
                            v{release.version}
                        </Badge>
                    )}
                    {release.isBatch && (
                        <Badge variant="secondary" className="font-normal">
                            batch
                        </Badge>
                    )}
                </span>
                <DispatchBadge
                    status={release.dispatchStatus}
                    dispatchedAt={release.dispatchedAt}
                    error={release.dispatchError}
                    downloadedAt={release.downloadedAt}
                />
            </div>

            {showLink && release.show && (
                <Link href={`/shows/${release.show.id}`} className="text-sm font-medium hover:underline">
                    {release.show.name}
                </Link>
            )}

            <p className="line-clamp-2 min-w-0 text-xs break-words text-muted-foreground">{release.title}</p>

            <p className="text-xs text-muted-foreground">
                Published <RelativeTime iso={release.publishedAt} /> · seen <RelativeTime iso={release.firstSeenAt} /> · delay{' '}
                {formatDelay(release.publishedAt, release.firstSeenAt)}
            </p>

            <div className="flex items-center gap-2 pt-1">
                {canDownload && (
                    <DownloadButton releaseId={release.id} title={release.title} retry={release.dispatchStatus === 'error'} variant="full" />
                )}
                <Button variant="outline" size="sm" className={`gap-1.5 ${TOUCH_TARGET_SM}`} onClick={() => copyLink(release.link)}>
                    <Copy className="size-3.5" />
                    Copy link
                </Button>
            </div>
        </div>
    );
}
