import { Badge } from '@/components/ui/badge';
import { type DashboardRelease } from '@/types/subtracker';

/**
 * The backend sets `isFirstEpisode` for episode 01 *or* for the show's earliest
 * known release — which, for a show Torii started watching mid-season, is e.g.
 * episode 25. Labelling that "Ep 1" would be wrong, so the badge only shows
 * when the flagged release is plausibly the premiere itself.
 */
export function isPremiere(release: DashboardRelease): boolean {
    if (!release.isFirstEpisode || release.isBatch) {
        return false;
    }

    const number = release.episode === null ? Number.NaN : Number.parseFloat(release.episode);

    return Number.isNaN(number) || number <= 1;
}

const SUBTLE = 'rounded-md px-1.5 py-0 text-[10px] leading-4 font-medium whitespace-nowrap';

export function NewShowBadge() {
    return (
        <Badge variant="outline" className={`${SUBTLE} border-sky-500/40 text-sky-700 dark:text-sky-300`}>
            new show
        </Badge>
    );
}

export function FirstEpisodeBadge() {
    return (
        <Badge variant="outline" className={`${SUBTLE} border-amber-500/50 text-amber-700 dark:text-amber-300`}>
            Ep 1
        </Badge>
    );
}
