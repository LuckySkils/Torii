import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { PackageOpen } from 'lucide-react';

export function BatchHint() {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <PackageOpen className="inline size-4 text-muted-foreground" aria-label="Batch release available" />
            </TooltipTrigger>
            <TooltipContent>A batch release is available for this show.</TooltipContent>
        </Tooltip>
    );
}
