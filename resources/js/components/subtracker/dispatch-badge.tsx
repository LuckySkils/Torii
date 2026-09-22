import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { formatRelative } from '@/lib/dates';
import { type DispatchStatus } from '@/types/subtracker';
import { CheckCircle2 } from 'lucide-react';

interface DispatchBadgeProps {
    status: DispatchStatus | null;
    dispatchedAt: string | null;
    error: string | null;
    downloadedAt?: string | null;
}

export function DispatchBadge({ status, dispatchedAt, error, downloadedAt = null }: DispatchBadgeProps) {
    if (downloadedAt !== null) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="inline-flex items-center gap-1 text-green-600 dark:text-green-500">
                        <CheckCircle2 className="size-4" />
                        <span className="text-sm">downloaded</span>
                    </span>
                </TooltipTrigger>
                <TooltipContent>Downloaded {formatRelative(downloadedAt)}</TooltipContent>
            </Tooltip>
        );
    }

    if (status === null) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    if (status === 'sent') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Badge className="border-transparent bg-green-600 text-white hover:bg-green-600/90">queued</Badge>
                </TooltipTrigger>
                <TooltipContent>{dispatchedAt ? `Queued ${formatRelative(dispatchedAt)}` : 'Queued'}</TooltipContent>
            </Tooltip>
        );
    }

    if (status === 'exists') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Badge variant="secondary">in qBit</Badge>
                </TooltipTrigger>
                <TooltipContent>{dispatchedAt ? `Already in qBit as of ${formatRelative(dispatchedAt)}` : 'Already in qBit'}</TooltipContent>
            </Tooltip>
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge variant="destructive">error</Badge>
            </TooltipTrigger>
            <TooltipContent>{error ?? 'Unknown error'}</TooltipContent>
        </Tooltip>
    );
}
