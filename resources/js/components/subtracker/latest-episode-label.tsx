import { Badge } from '@/components/ui/badge';
import { type LatestRelease } from '@/types/subtracker';

interface LatestEpisodeLabelProps {
    latest: LatestRelease | null | undefined;
}

export function LatestEpisodeLabel({ latest }: LatestEpisodeLabelProps) {
    if (!latest) {
        return <span className="text-muted-foreground">—</span>;
    }

    if (latest.isBatch) {
        const range =
            latest.batchFrom != null && latest.batchTo != null
                ? `${String(latest.batchFrom).padStart(2, '0')}–${String(latest.batchTo).padStart(2, '0')}`
                : null;

        return (
            <Badge variant="secondary" className="font-normal">
                Batch{range ? ` ${range}` : ''}
            </Badge>
        );
    }

    return <span>Ep {latest.episode ?? '—'}</span>;
}
