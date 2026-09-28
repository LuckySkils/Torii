import { animeTitle } from '@/lib/anime';
import { cn, TOUCH_TARGET_MD } from '@/lib/utils';
import { type AnimeLinkSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Link2 } from 'lucide-react';
import { useState } from 'react';
import { LinkAnimeDialog } from './link-anime-dialog';
import { TapInfo } from './tap-info';

interface AnimeLinkIndicatorProps {
    show: { id: number; name: string; anime: AnimeLinkSummary | null; hasSuggestions: boolean };
    /** Newest episode Torii has, so the dialog can explain episode-count mismatches. */
    currentEpisode?: number | null;
    className?: string;
}

/**
 * The quiet metadata marker on cards, rows and dashboard entries: a link icon
 * when linked (accented for manual links), a "review" badge when suggestions are
 * waiting (opens the link dialog on Suggestions), nothing otherwise.
 */
export function AnimeLinkIndicator({ show, currentEpisode = null, className }: AnimeLinkIndicatorProps) {
    const [dialogOpen, setDialogOpen] = useState(false);

    if (!show.anime && !show.hasSuggestions) {
        return null;
    }

    return (
        <span className={cn('inline-flex items-center gap-1', className)}>
            {show.anime && <LinkedIcon anime={show.anime} />}
            {show.hasSuggestions && (
                <>
                    <button
                        type="button"
                        onClick={() => setDialogOpen(true)}
                        className={cn(
                            'inline-flex cursor-pointer items-center gap-1 rounded-md border border-amber-500/50 px-1.5 text-[10px] leading-4 font-medium text-amber-700 hover:bg-amber-500/10 dark:text-amber-300',
                            TOUCH_TARGET_MD,
                        )}
                        aria-label={`Review anime link suggestions for ${show.name}`}
                    >
                        <span className="size-1.5 rounded-full bg-amber-500" aria-hidden />
                        review
                    </button>
                    <LinkAnimeDialog
                        show={show}
                        open={dialogOpen}
                        onOpenChange={setDialogOpen}
                        hasSuggestions
                        initialTab="suggestions"
                        currentEpisode={currentEpisode}
                    />
                </>
            )}
        </span>
    );
}

function LinkedIcon({ anime }: { anime: AnimeLinkSummary }) {
    const manual = anime.linkSource === 'manual';

    return (
        <TapInfo
            trigger={
                <button
                    type="button"
                    className={cn(
                        'inline-flex size-5 cursor-pointer items-center justify-center rounded-full',
                        manual ? 'bg-sky-500/15 text-sky-700 dark:text-sky-300' : 'text-muted-foreground',
                    )}
                    aria-label={`Linked to ${animeTitle(anime)} (${anime.linkSource})`}
                >
                    <Link2 className="size-3.5" />
                </button>
            }
        >
            Linked to{' '}
            <Link href={`/anime/${anime.id}`} className="font-medium underline-offset-2 hover:underline">
                {animeTitle(anime)}
            </Link>{' '}
            ({anime.linkSource})
        </TapInfo>
    );
}
