import { Button } from '@/components/ui/button';
import { animeSubtitle, animeTitle } from '@/lib/anime';
import { ACTION_HINTS } from '@/lib/hints';
import { type AnimeLinkFull } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { ExternalLink, Link2, Link2Off } from 'lucide-react';
import { useState } from 'react';
import { AiringWindowStrip } from './airing-window';
import { AnimeDescription } from './anime-description';
import { AnimeFacts, GenreBadges } from './anime-facts';
import { ActionHint } from './hint';
import { type LinkDialogTab, LinkAnimeDialog } from './link-anime-dialog';
import { UnlinkAnimeDialog } from './unlink-anime-dialog';

interface ShowAnimePanelProps {
    show: { id: number; name: string; hasSuggestions: boolean; anime: AnimeLinkFull | null };
    /** Newest episode Torii has; lets the link dialog explain episode-count mismatches. */
    currentEpisode: number | null;
}

/** The show page's metadata section: the linked AniList entry, or the way to link one. */
export function ShowAnimePanel({ show, currentEpisode }: ShowAnimePanelProps) {
    const [dialog, setDialog] = useState<{ open: boolean; tab?: LinkDialogTab }>({ open: false });
    const anime = show.anime;

    const linkDialog = (
        <LinkAnimeDialog
            show={show}
            open={dialog.open}
            onOpenChange={(open) => setDialog((current) => ({ ...current, open }))}
            hasSuggestions={show.hasSuggestions}
            initialTab={dialog.tab}
            currentEpisode={currentEpisode}
        />
    );

    if (!anime) {
        return (
            <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-dashed p-3 sm:p-4">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <p className="text-sm font-medium">Not linked to AniList</p>
                    <p className="text-sm text-muted-foreground">
                        {show.hasSuggestions
                            ? 'Automatic matching found a few candidates too close to call. Pick the right one.'
                            : 'Link it to get the anime’s titles, episode counts and air dates.'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    {show.hasSuggestions && (
                        <Button size="sm" onClick={() => setDialog({ open: true, tab: 'suggestions' })}>
                            Review suggestions
                        </Button>
                    )}
                    <Button size="sm" variant="outline" className="gap-1.5" onClick={() => setDialog({ open: true, tab: 'search' })}>
                        <Link2 className="size-4" />
                        Link anime
                    </Button>
                </div>
                {linkDialog}
            </section>
        );
    }

    const subtitle = animeSubtitle(anime);

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-3 sm:p-4">
            <div className="flex flex-col gap-0.5">
                <h2 className="text-lg leading-tight font-medium break-words large:md:text-2xl">{animeTitle(anime)}</h2>
                {subtitle && <p className="text-sm break-words text-muted-foreground large:md:text-base">{subtitle}</p>}
                <p className="text-xs text-muted-foreground">SubsPlease name: {show.name}</p>
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
            <AiringWindowStrip airingWindow={anime.airingWindow} className="large:md:max-w-3xl" />
            <GenreBadges genres={anime.genres} />
            {anime.description && <AnimeDescription html={anime.description} />}

            <div className="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm large:md:text-base">
                <Link href={`/anime/${anime.id}`} className="font-medium hover:underline">
                    Air dates & full details
                </Link>
                {anime.siteUrl && (
                    <a
                        href={anime.siteUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 font-medium hover:underline"
                    >
                        View on AniList
                        <ExternalLink className="size-3.5" />
                    </a>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 border-t pt-3 text-sm text-muted-foreground">
                <span className="inline-flex items-center gap-1.5">
                    <Link2 className={anime.linkSource === 'manual' ? 'size-4 text-sky-600 dark:text-sky-400' : 'size-4'} />
                    {anime.linkSource === 'manual' ? 'Linked manually' : `Linked automatically (score ${anime.confidence})`}
                </span>
                <div className="flex flex-wrap gap-2 sm:ml-auto">
                    <ActionHint hint={ACTION_HINTS.changeLink}>
                        <Button size="sm" variant="outline" onClick={() => setDialog({ open: true, tab: 'search' })}>
                            Change link
                        </Button>
                    </ActionHint>
                    <UnlinkAnimeDialog
                        showId={show.id}
                        showName={show.name}
                        animeTitle={animeTitle(anime)}
                        trigger={
                            <ActionHint hint={ACTION_HINTS.unlink}>
                                <Button size="sm" variant="ghost" className="gap-1.5 text-destructive hover:text-destructive">
                                    <Link2Off className="size-4" />
                                    Unlink
                                </Button>
                            </ActionHint>
                        }
                    />
                </div>
            </div>

            {linkDialog}
        </section>
    );
}
