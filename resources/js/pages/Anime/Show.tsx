import { AiringWindowStrip } from '@/components/subtracker/airing-window';
import { AnimeDescription } from '@/components/subtracker/anime-description';
import { AnimeFacts, GenreBadges, NextEpisodeLine } from '@/components/subtracker/anime-facts';
import { ColumnHint } from '@/components/subtracker/hint';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { ShowPoster } from '@/components/subtracker/show-poster';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { animeSubtitle, animeTitle, coverPoster } from '@/lib/anime';
import { COLUMN_HINTS } from '@/lib/hints';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AiringWindow, type AnimeAiring, type AnimeShowProps } from '@/types/subtracker';
import { Head, Link } from '@inertiajs/react';
import { Check, ExternalLink, Link2 } from 'lucide-react';
import { useState } from 'react';

const RECENT_AIRINGS = 25;

const airDateTime = new Intl.DateTimeFormat(undefined, {
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
});

export default function AnimeShow({ anime, airings, linkedShows }: AnimeShowProps) {
    const title = animeTitle(anime);
    const subtitle = animeSubtitle(anime);
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Anime', href: '/anime' },
        { title, href: `/anime/${anime.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />
            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                <div className="flex flex-col gap-4 rounded-xl border p-3 sm:p-4 md:flex-row">
                    <div className="mx-auto w-full max-w-[min(240px,65vw)] shrink-0 md:mx-0 md:w-56 md:max-w-none large:md:w-72 large:xl:w-80 large:3xl:w-96">
                        <ShowPoster {...coverPoster(anime)} name={title} className="w-full" />
                    </div>

                    <div className="flex min-w-0 flex-1 flex-col gap-3 large:md:gap-4">
                        <div className="flex flex-col gap-0.5">
                            <h1 className="text-xl leading-tight font-medium break-words large:md:text-3xl">{title}</h1>
                            {subtitle && <p className="text-sm break-words text-muted-foreground large:md:text-lg">{subtitle}</p>}
                            {anime.titleNative && <p className="text-sm break-words text-muted-foreground large:md:text-base">{anime.titleNative}</p>}
                        </div>

                        <AnimeFacts
                            format={anime.format}
                            season={anime.season}
                            seasonYear={anime.seasonYear}
                            status={anime.status}
                            episodesAired={anime.episodesAired}
                            episodesTotal={anime.episodesTotal}
                            durationMinutes={anime.durationMinutes}
                        />
                        <NextEpisodeLine nextAiringAt={anime.nextAiringAt} nextEpisode={anime.nextEpisode} />
                        <GenreBadges genres={anime.genres} />
                        {anime.description && <AnimeDescription html={anime.description} />}

                        {anime.siteUrl && (
                            <a
                                href={anime.siteUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex w-fit items-center gap-1 text-sm font-medium hover:underline large:md:text-base"
                            >
                                View on AniList
                                <ExternalLink className="size-3.5" />
                            </a>
                        )}
                    </div>
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Linked shows</h2>
                    {linkedShows.length === 0 ? (
                        <p className="rounded-xl border p-4 text-sm text-muted-foreground">Not linked to any show.</p>
                    ) : (
                        <div className="flex flex-col gap-2">
                            {linkedShows.map((show) => (
                                <Link
                                    key={show.id}
                                    href={`/shows/${show.id}`}
                                    className="flex items-center gap-3 rounded-xl border p-2 hover:bg-muted/50"
                                >
                                    <ShowPoster
                                        imageUrl={show.imageUrl}
                                        imageStatus={show.imageUrl ? 'found' : 'none'}
                                        name={show.name}
                                        className="w-10"
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="text-sm font-medium break-words">{show.name}</span>
                                        <span className="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                            <Link2 className={cn('size-3', show.linkSource === 'manual' && 'text-sky-600 dark:text-sky-400')} />
                                            {show.linkSource === 'manual' ? 'linked manually' : `linked automatically (score ${show.confidence})`}
                                            {' · '}
                                            <RelativeTime iso={show.linkedAt} />
                                        </span>
                                    </div>
                                    {show.isTracked && (
                                        <Badge className="shrink-0 border-transparent bg-green-600 text-white hover:bg-green-600/90">tracked</Badge>
                                    )}
                                </Link>
                            ))}
                        </div>
                    )}
                </section>

                <AiringsSection airings={airings} airingWindow={anime.airingWindow} />
            </div>
        </AppLayout>
    );
}

function AiringsSection({ airings, airingWindow }: { airings: AnimeAiring[]; airingWindow: AiringWindow }) {
    const [showAll, setShowAll] = useState(false);
    const now = Date.now();
    // Newest first, so the latest (and any upcoming) episodes lead the list.
    const visible = showAll ? airings : airings.slice(0, RECENT_AIRINGS);
    // The backend's window decides what's next up, so list and strip always agree.
    const nextEpisode = airingWindow.current?.episode ?? null;

    return (
        <section className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h2 className="text-lg font-medium">Airings</h2>
                {airings.length > RECENT_AIRINGS && (
                    <Button variant="ghost" size="sm" onClick={() => setShowAll((value) => !value)}>
                        {showAll ? `Show the latest ${RECENT_AIRINGS}` : `Show all ${airings.length}`}
                    </Button>
                )}
            </div>

            <AiringWindowStrip airingWindow={airingWindow} className="large:md:max-w-3xl" />

            {airings.length === 0 ? (
                <p className="rounded-xl border p-4 text-sm text-muted-foreground">
                    No air dates known yet. They're filled in by the daily airing sync.
                </p>
            ) : (
                <div className="max-h-[28rem] overflow-y-auto rounded-xl border large:md:max-h-[40rem]">
                    <div className="sticky top-0 z-10 flex items-center gap-3 border-b bg-background px-3 py-2 text-xs font-medium text-muted-foreground large:md:text-sm">
                        <span className="w-16 shrink-0">Episode</span>
                        <span className="min-w-0 flex-1">
                            <ColumnHint label="Airs">{COLUMN_HINTS.airingTime}</ColumnHint>
                        </span>
                        <span className="shrink-0">
                            <ColumnHint label="State">{COLUMN_HINTS.airingState}</ColumnHint>
                        </span>
                    </div>
                    <ol className="divide-y">
                        {visible.map((airing) => {
                            const past = new Date(airing.airsAt).getTime() <= now;
                            const isNext = airing.episode === nextEpisode;

                            return (
                                <li
                                    key={airing.episode}
                                    className={cn(
                                        'flex items-center gap-3 px-3 py-2 text-sm large:md:py-2.5 large:md:text-base',
                                        past && 'text-muted-foreground',
                                        isNext && 'bg-sky-500/5',
                                    )}
                                >
                                    <span className={cn('w-16 shrink-0 font-medium tabular-nums', !past && 'text-foreground')}>
                                        Ep {airing.episode}
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        {airDateTime.format(new Date(airing.airsAt))}
                                        {airing.isEstimate && <span className="text-xs"> (estimated)</span>}
                                    </span>
                                    {past ? (
                                        <Check className="size-4 shrink-0" aria-label="aired" />
                                    ) : (
                                        <span
                                            className={cn(
                                                'shrink-0 text-xs',
                                                isNext ? 'font-medium text-sky-700 dark:text-sky-300' : 'text-muted-foreground',
                                            )}
                                        >
                                            {isNext ? 'next · ' : ''}
                                            <RelativeTime iso={airing.airsAt} />
                                        </span>
                                    )}
                                </li>
                            );
                        })}
                    </ol>
                </div>
            )}
        </section>
    );
}
