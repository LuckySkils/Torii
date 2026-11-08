import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/** The feed's own group: not worth a badge on every row. */
const DEFAULT_GROUP = 'SubsPlease';

/** The release group as a small badge, only when it isn't SubsPlease (a Nyaa import from another group). */
export function ReleaseGroupBadge({ group, className }: { group: string | null | undefined; className?: string }) {
    if (!group || group === DEFAULT_GROUP) {
        return null;
    }

    return (
        <Badge variant="outline" className={cn('max-w-40 truncate font-normal text-muted-foreground', className)} title={`Release group: ${group}`}>
            {group}
        </Badge>
    );
}
