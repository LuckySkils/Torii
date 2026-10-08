import { coverPoster, formatLabel } from '@/lib/anime';
import { SCHEDULE_HINTS } from '@/lib/hints';
import { scheduleTitle, timeOfDay } from '@/lib/schedule';
import { cn, ENTRY_COVER } from '@/lib/utils';
import { type ScheduleAiring } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Check, Link2 } from 'lucide-react';
import { type ReactNode } from 'react';
import { AnimeCardCover, AnimeDetailCard } from './anime-detail-card';
import { ShowPoster } from './show-poster';

const BADGE =
    'inline-flex items-center gap-0.5 rounded-md border px-1 text-[10px] leading-4 font-medium whitespace-nowrap large:md:px-1.5 large:md:text-[11px]';

function Tag({ className, title, children }: { className?: string; title?: string; children: ReactNode }) {
    return (
        <span className={cn(BADGE, className)} title={title}>
            {children}
        </span>
    );
}

/**
 * format · new · linked · tracked · downloaded · released · waiting · 18+ · estimate, each
 * only when it applies. Unlinked entries simply have fewer badges.
 */
export function ScheduleBadges({ airing }: { airing: ScheduleAiring }) {
    const { anime, show, releaseState } = airing;
    const format = formatLabel(anime.format);

    return (
        <span className="flex flex-wrap items-center gap-1">
            {format && <Tag className="text-muted-foreground">{format}</Tag>}
            {airing.isNewSeries && (
                <Tag className="border-amber-500/50 text-amber-700 dark:text-amber-300" title={SCHEDULE_HINTS.new}>
                    new
                </Tag>
            )}
            {show && (
                <Link
                    href={`/shows/${show.id}`}
                    className={cn(BADGE, 'border-sky-500/40 text-sky-700 hover:bg-sky-500/10 dark:text-sky-300')}
                    title={`${SCHEDULE_HINTS.linked} (${show.name})`}
                >
                    <Link2 className="size-2.5" aria-hidden />
                    linked
                </Link>
            )}
            {show?.isTracked && (
                <Tag className="border-green-600/40 text-green-700 dark:text-green-400" title={SCHEDULE_HINTS.tracked}>
                    tracked
                </Tag>
            )}
            {releaseState === 'downloaded' && (
                <Tag className="border-transparent bg-green-600/15 text-green-700 dark:text-green-400" title={SCHEDULE_HINTS.downloaded}>
                    <Check className="size-2.5" aria-hidden />
                    downloaded
                </Tag>
            )}
            {releaseState === 'released' && (
                <Tag className="border-indigo-500/40 text-indigo-700 dark:text-indigo-300" title={SCHEDULE_HINTS.released}>
                    released
                </Tag>
            )}
            {releaseState === 'waiting' && show?.isTracked && (
                <Tag className="border-orange-500/50 text-orange-700 dark:text-orange-300" title={SCHEDULE_HINTS.waiting}>
                    waiting
                </Tag>
            )}
            {anime.isAdult && (
                <Tag className="border-rose-500/40 text-rose-700 dark:text-rose-300" title={SCHEDULE_HINTS.adult}>
                    18+
                </Tag>
            )}
            {airing.isEstimate && (
                <Tag className="border-dashed text-muted-foreground" title={SCHEDULE_HINTS.estimate}>
                    estimate
                </Tag>
            )}
        </span>
    );
}

interface EntryProps {
    airing: ScheduleAiring;
    past: boolean;
}

/** Week board card: cover on the left, the time, a two-line title and badges beside it; its height follows the cover. */
export function ScheduleCard({ airing, past }: EntryProps) {
    const title = scheduleTitle(airing.anime);

    return (
        <AnimeDetailCard animeId={airing.anime.id} badges={<ScheduleBadges airing={airing} />}>
            <li className={cn('flex items-start gap-2.5 rounded-lg border bg-card p-2 large:md:gap-3 large:md:p-2.5', past && 'opacity-55')}>
                <EntryCover airing={airing} title={title} className={ENTRY_COVER} />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <span className="text-xs text-muted-foreground tabular-nums large:md:text-sm">
                        <time dateTime={airing.airsAt} className="font-medium text-foreground">
                            {timeOfDay.format(new Date(airing.airsAt))}
                        </time>{' '}
                        · Ep {airing.episode}
                    </span>
                    <Link
                        href={`/anime/${airing.anime.id}`}
                        className="line-clamp-2 text-sm leading-snug font-medium break-words hover:underline large:md:text-base"
                    >
                        {title}
                    </Link>
                    <ScheduleBadges airing={airing} />
                </div>
            </li>
        </AnimeDetailCard>
    );
}

/** Today list row: led by its local time, then the cover and the title, episode and badges beside it. */
export function ScheduleRow({ airing, past }: EntryProps) {
    const title = scheduleTitle(airing.anime);

    return (
        <AnimeDetailCard animeId={airing.anime.id} badges={<ScheduleBadges airing={airing} />}>
            <li className={cn('flex items-start gap-3 px-3 py-2.5 large:md:gap-4 large:md:py-3', past && 'opacity-55')}>
                <time dateTime={airing.airsAt} className="w-12 shrink-0 pt-0.5 text-sm font-medium tabular-nums large:md:w-16 large:md:text-base">
                    {timeOfDay.format(new Date(airing.airsAt))}
                </time>
                <EntryCover airing={airing} title={title} className={ENTRY_COVER} />
                <div className="flex min-w-0 flex-1 flex-col gap-1">
                    <Link
                        href={`/anime/${airing.anime.id}`}
                        className="line-clamp-2 text-sm leading-snug font-medium break-words hover:underline large:md:text-base"
                    >
                        {title}
                    </Link>
                    <span className="text-xs text-muted-foreground tabular-nums large:md:text-sm">Ep {airing.episode}</span>
                    <ScheduleBadges airing={airing} />
                </div>
            </li>
        </AnimeDetailCard>
    );
}

/** The entry's cover: a link to the anime page, or on touch the way to open its detail card. */
function EntryCover({ airing, title, className }: { airing: ScheduleAiring; title: string; className: string }) {
    return (
        <AnimeCardCover>
            <Link href={`/anime/${airing.anime.id}`} className="shrink-0 self-start" aria-label={`${title}: details`}>
                <ShowPoster {...coverPoster(airing.anime)} name={title} className={className} />
            </Link>
        </AnimeCardCover>
    );
}

/** The divider between what has aired and what's coming, with the current time. */
export function NowLine({ now, className }: { now: Date; className?: string }) {
    return (
        <li className={cn('flex items-center gap-2 text-[11px] font-medium text-sky-700 dark:text-sky-300', className)} aria-label="Now">
            <span className="h-px flex-1 bg-sky-500/60" />
            now · {timeOfDay.format(now)}
            <span className="h-px flex-1 bg-sky-500/60" />
        </li>
    );
}
