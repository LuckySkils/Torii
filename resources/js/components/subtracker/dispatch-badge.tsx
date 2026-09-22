import { Badge } from '@/components/ui/badge';
import { formatRelative } from '@/lib/dates';
import { type DispatchStatus } from '@/types/subtracker';
import { CheckCircle2 } from 'lucide-react';
import { TapInfo } from './tap-info';

interface DispatchBadgeProps {
    status: DispatchStatus | null;
    dispatchedAt: string | null;
    error: string | null;
    downloadedAt?: string | null;
}

export function DispatchBadge({ status, dispatchedAt, error, downloadedAt = null }: DispatchBadgeProps) {
    if (downloadedAt !== null) {
        return (
            <TapInfo
                trigger={
                    <button type="button" className="inline-flex cursor-pointer items-center gap-1 bg-transparent p-0 text-green-600 dark:text-green-500">
                        <CheckCircle2 className="size-4" />
                        <span className="text-sm">downloaded</span>
                    </button>
                }
            >
                Downloaded {formatRelative(downloadedAt)}
            </TapInfo>
        );
    }

    if (status === null) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    if (status === 'sent') {
        return (
            <TapInfo trigger={<Badge className="cursor-pointer border-transparent bg-green-600 text-white hover:bg-green-600/90">queued</Badge>}>
                {dispatchedAt ? `Queued ${formatRelative(dispatchedAt)}` : 'Queued'}
            </TapInfo>
        );
    }

    if (status === 'exists') {
        return (
            <TapInfo trigger={<Badge variant="secondary" className="cursor-pointer">in qBit</Badge>}>
                {dispatchedAt ? `Already in qBit as of ${formatRelative(dispatchedAt)}` : 'Already in qBit'}
            </TapInfo>
        );
    }

    return (
        <TapInfo trigger={<Badge variant="destructive" className="cursor-pointer">error</Badge>}>
            {error ?? 'Unknown error'}
        </TapInfo>
    );
}
