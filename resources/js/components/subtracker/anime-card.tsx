import { animeTitle, coverPoster, episodeCount, formatLabel } from '@/lib/anime';
import { cn, TOUCH_TARGET_SM } from '@/lib/utils';
import { type AnimeItem } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { ShowPoster } from './show-poster';

export function AnimeCard({ anime }: { anime: AnimeItem }) {
    const title = animeTitle(anime);
    const facts = [formatLabel(anime.format), episodeCount(anime.episodesAired, anime.episodesTotal)].filter(Boolean).join(' · ');
    const [firstShow, ...otherShows] = anime.linkedShows;

    return (
        <div className="flex min-w-0 flex-col gap-2 rounded-lg border p-2">
            <Link href={`/anime/${anime.id}`} className="flex flex-col gap-2">
                <ShowPoster {...coverPoster(anime)} name={title} className="w-full" />
                <span className="line-clamp-2 text-sm leading-tight font-medium hover:underline" title={title}>
                    {title}
                </span>
            </Link>
            {facts && <span className="truncate text-xs text-muted-foreground">{facts}</span>}
            {firstShow && (
                <Link
                    href={`/shows/${firstShow.id}`}
                    className={cn(
                        'inline-flex max-w-full min-w-0 items-center gap-1 self-start rounded-md border px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground',
                        TOUCH_TARGET_SM,
                    )}
                    title={`Linked to ${anime.linkedShows.map((show) => show.name).join(', ')}`}
                >
                    <Link2 className={cn('size-3 shrink-0', firstShow.linkSource === 'manual' && 'text-sky-600 dark:text-sky-400')} />
                    <span className="truncate">{firstShow.name}</span>
                    {otherShows.length > 0 && <span className="shrink-0">+{otherShows.length}</span>}
                </Link>
            )}
        </div>
    );
}
