import { Badge } from '@/components/ui/badge';
import { animeSeasonLabel, episodeCount, formatLabel, statusLabel } from '@/lib/anime';
import { formatAbsolute, formatRelative } from '@/lib/dates';
import { TapInfo } from './tap-info';

interface AnimeFactsProps {
    format?: string | null;
    season: string | null;
    seasonYear: number | null;
    status: string | null;
    episodesAired: number | null;
    episodesTotal: number | null;
    durationMinutes?: number | null;
}

/** "TV · Autumn 2026 · Airing · 12 / 24 eps · 24 min", skipping whatever is unknown. */
export function AnimeFacts({ format = null, season, seasonYear, status, episodesAired, episodesTotal, durationMinutes = null }: AnimeFactsProps) {
    const facts = [
        formatLabel(format),
        animeSeasonLabel(season, seasonYear),
        statusLabel(status),
        episodeCount(episodesAired, episodesTotal, status),
        durationMinutes ? `${durationMinutes} min` : null,
    ].filter((fact): fact is string => fact !== null);

    if (facts.length === 0) {
        return null;
    }

    return <p className="text-sm text-muted-foreground large:md:text-base">{facts.join(' · ')}</p>;
}

export function GenreBadges({ genres }: { genres: string[] }) {
    if (genres.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-wrap gap-1.5">
            {genres.map((genre) => (
                <Badge key={genre} variant="secondary" className="font-normal large:md:px-2.5 large:md:text-sm">
                    {genre}
                </Badge>
            ))}
        </div>
    );
}

const weekdayTime = new Intl.DateTimeFormat(undefined, { weekday: 'short', hour: '2-digit', minute: '2-digit' });

/** "Ep 13 airs in 2 days (Thu 01:30)", in the browser's timezone; the full date in a tap-info. */
export function NextEpisodeLine({ nextAiringAt, nextEpisode }: { nextAiringAt: string | null; nextEpisode: number | null }) {
    if (!nextAiringAt) {
        return null;
    }

    const when = new Date(nextAiringAt);

    return (
        <p className="text-sm large:md:text-base">
            {nextEpisode !== null ? `Ep ${nextEpisode}` : 'Next episode'} airs{' '}
            <TapInfo
                trigger={
                    <button type="button" className="cursor-pointer bg-transparent p-0 underline decoration-dotted underline-offset-2">
                        {formatRelative(nextAiringAt)} ({weekdayTime.format(when)})
                    </button>
                }
            >
                {formatAbsolute(nextAiringAt)}
            </TapInfo>
        </p>
    );
}
