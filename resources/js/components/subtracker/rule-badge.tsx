import { Badge } from '@/components/ui/badge';
import { type RuleState, type TrackingMode } from '@/types/subtracker';
import { Loader2 } from 'lucide-react';
import { TapInfo } from './tap-info';

interface RuleBadgeProps {
    trackingMode: TrackingMode | null;
    state: RuleState;
    error: string | null;
}

export function RuleBadge({ trackingMode, state, error }: RuleBadgeProps) {
    if (trackingMode === 'batch') {
        return (
            <TapInfo trigger={<Badge className="cursor-pointer border-transparent bg-blue-600 text-white hover:bg-blue-600/90">batch</Badge>}>
                Downloaded as a batch; no RSS rule
            </TapInfo>
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
        <TapInfo trigger={<Badge variant="destructive" className="cursor-pointer">error</Badge>}>
            {error ?? 'Unknown error'}
        </TapInfo>
    );
}
