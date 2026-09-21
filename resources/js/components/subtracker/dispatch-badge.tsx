import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type DispatchStatus } from '@/types/subtracker';

interface DispatchBadgeProps {
    status: DispatchStatus | null;
    error: string | null;
}

export function DispatchBadge({ status, error }: DispatchBadgeProps) {
    if (status === null) {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    if (status === 'sent') {
        return <Badge variant="secondary">queued</Badge>;
    }

    if (status === 'exists') {
        return <Badge className="border-transparent bg-green-600 text-white hover:bg-green-600/90">have it</Badge>;
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
