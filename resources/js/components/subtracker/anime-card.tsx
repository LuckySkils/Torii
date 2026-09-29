import { animeTitle, coverPoster, episodeCount, formatLabel } from '@/lib/anime';
import { cn, TOUCH_TARGET_SM } from '@/lib/utils';
import { type AnimeItem } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { AnimeCardCover, AnimeDetailCard } from './anime-detail-card';
import { ShowPoster } from './show-poster';

export function AnimeCard({ anime }: { anime: AnimeItem }) {
    const title = animeTitle(anime);
    const facts = [formatLabel(anime.format), episodeCount(anime.episodesAired, anime.episodesTotal, anime.status)].filter(Boolean).join(' · ');
    const [firstShow, ...otherShows] = anime.linkedShows;

    const adult = anime.isAdult ? (
        <span className="shrink-0 rounded border border-rose-500/40 px-1 text-[10px] leading-4 font-medium text-rose-700 dark:text-rose-300">
            18+
        </span>
    ) : undefined;

    return (
        <AnimeDetailCard animeId={anime.id} badges={adult}>
            <div className="flex min-w-0 flex-col gap-2 rounded-lg border p-2 large:md:p-2.5">
                <AnimeCardCover>
                    <Link href={`/anime/${anime.id}`} className="block" aria-label={`${title}: details`}>
                        <ShowPoster {...coverPoster(anime)} name={title} className="w-full" />
                    </Link>
                </AnimeCardCover>
                <Link href={`/anime/${anime.id}`} className="line-clamp-2 text-sm leading-tight font-medium hover:underline large:md:text-base">
                    {title}
                </Link>
                {(facts || adult) && (
                    <span className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground large:md:text-sm">
                        {adult}
                        <span className="truncate">{facts}</span>
                    </span>
                )}
                {firstShow && (
                    <Link
                        href={`/shows/${firstShow.id}`}
                        className={cn(
                            'inline-flex max-w-full min-w-0 items-center gap-1 self-start rounded-md border px-1.5 py-0.5 text-[11px] text-muted-foreground hover:bg-muted hover:text-foreground large:md:text-xs',
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
        </AnimeDetailCard>
    );
}
