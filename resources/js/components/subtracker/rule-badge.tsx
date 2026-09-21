import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type RuleState, type TrackingMode } from '@/types/subtracker';
import { Loader2 } from 'lucide-react';

interface RuleBadgeProps {
    trackingMode: TrackingMode | null;
    state: RuleState;
    error: string | null;
}

export function RuleBadge({ trackingMode, state, error }: RuleBadgeProps) {
    if (trackingMode === 'batch') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <Badge className="border-transparent bg-blue-600 text-white hover:bg-blue-600/90">batch</Badge>
                </TooltipTrigger>
                <TooltipContent>Downloaded as a batch; no RSS rule</TooltipContent>
            </Tooltip>
        );
    }

    if (state === 'none') {
        return <span className="text-sm text-muted-foreground">—</span>;
    }

    if (state === 'pending') {
        return (
            <Badge variant="secondary" className="gap-1">
                <Loader2 className="size-3 animate-spin" />
                syncing
            </Badge>
        );
    }

    if (state === 'synced') {
        return <Badge className="border-transparent bg-green-600 text-white hover:bg-green-600/90">active</Badge>;
    }

    if (state === 'disabled') {
        return <Badge variant="secondary">disabled</Badge>;
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
