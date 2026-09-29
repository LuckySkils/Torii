import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { useAnimeCard } from '@/hooks/use-anime-card';
import { animeSeasonLabel, animeSubtitle, animeTitle, coverPoster, episodeCount, formatLabel, statusLabel } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type AnimeCardData } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Slot } from '@radix-ui/react-slot';
import { Link2 } from 'lucide-react';
import {
    createContext,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type FocusEvent,
    type MouseEvent,
    type ReactElement,
    type ReactNode,
} from 'react';
import { sanitizeAniListHtml } from './anime-description';
import { useIsTouch } from './hint';
import { ShowPoster } from './show-poster';

/** Long enough that scanning across the week board doesn't fire cards constantly. */
const OPEN_DELAY_MS = 350;
/** Time to move the pointer from the entry onto the card without it closing. */
const CLOSE_GRACE_MS = 150;

/** Set on touch devices: lets the entry's cover open the card instead of navigating. */
const TapToOpen = createContext<(() => void) | null>(null);

interface AnimeDetailCardProps {
    animeId: number;
    /** The entry's own badges (tracked, downloaded, …), shown in the card so that state isn't lost. */
    badges?: ReactNode;
    /**
     * The entry. On pointer devices hovering or focusing it opens the card (it must
     * accept a ref: a DOM element or a ref-forwarding component). On touch, wrap its
     * cover in <AnimeCardCover>; tapping that opens the card, the rest keeps navigating.
     */
    children: ReactElement;
    side?: 'right' | 'left' | 'top' | 'bottom';
}

/**
 * Detail card for one anime (GET /anime/{id}/card): a delayed hover popover with
 * collision handling on desktop, a bottom sheet opened from the cover on touch.
 */
export function AnimeDetailCard({ animeId, badges, children, side = 'right' }: AnimeDetailCardProps) {
    const touch = useIsTouch();
    const [open, setOpen] = useState(false);
    const openTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const closeTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const contentRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        return () => {
            clearTimeout(openTimer.current);
            clearTimeout(closeTimer.current);
        };
    }, []);

    function scheduleOpen() {
        clearTimeout(closeTimer.current);

        if (!open) {
            clearTimeout(openTimer.current);
            openTimer.current = setTimeout(() => setOpen(true), OPEN_DELAY_MS);
        }
    }

    function scheduleClose() {
        clearTimeout(openTimer.current);
        clearTimeout(closeTimer.current);
        closeTimer.current = setTimeout(() => setOpen(false), CLOSE_GRACE_MS);
    }

    function keepOpen() {
        clearTimeout(closeTimer.current);
    }

    function closeNow() {
        clearTimeout(openTimer.current);
        clearTimeout(closeTimer.current);
        setOpen(false);
    }

    if (touch) {
        return (
            <TapToOpen.Provider value={() => setOpen(true)}>
                {children}
                <Sheet open={open} onOpenChange={setOpen}>
                    <SheetContent side="bottom" className="max-h-[85vh] gap-0 overflow-y-auto p-0 pb-[env(safe-area-inset-bottom)]">
                        <SheetTitle className="sr-only">Anime details</SheetTitle>
                        <SheetDescription className="sr-only">Description, genres and links for this anime.</SheetDescription>
                        <CardBody animeId={animeId} active={open} badges={badges} className="p-4 pr-12" />
                        <div className="px-4 pb-4">
                            <SheetClose asChild>
                                <Button variant="outline" className="w-full">
                                    Close
                                </Button>
                            </SheetClose>
                        </div>
                    </SheetContent>
                </Sheet>
            </TapToOpen.Provider>
        );
    }

    return (
        <Popover open={open} onOpenChange={(next) => (next ? setOpen(true) : closeNow())}>
            <PopoverAnchor
                asChild
                onMouseEnter={scheduleOpen}
                onMouseLeave={scheduleClose}
                onFocus={scheduleOpen}
                onBlur={(event: FocusEvent) => {
                    if (!contentRef.current?.contains(event.relatedTarget as Node | null)) {
                        scheduleClose();
                    }
                }}
            >
                {children}
            </PopoverAnchor>
            <PopoverContent
                ref={contentRef}
                side={side}
                align="start"
                collisionPadding={12}
                className="w-80 p-0 large:md:w-[26rem]"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => event.preventDefault()}
                onMouseEnter={keepOpen}
                onMouseLeave={scheduleClose}
            >
                <CardBody animeId={animeId} active={open} badges={badges} className="p-3 large:md:p-4" />
            </PopoverContent>
        </Popover>
    );
}

/**
 * Wraps an entry's cover. On touch, tapping it opens the detail card rather than
 * following the cover's link; on pointer devices it changes nothing.
 */
export function AnimeCardCover({ children }: { children: ReactElement }) {
    const openCard = useContext(TapToOpen);

    if (!openCard) {
        return children;
    }

    return (
        <Slot
            onClick={(event: MouseEvent) => {
                // Inertia's Link skips its visit when the click was already prevented.
                event.preventDefault();
                openCard();
            }}
        >
            {children}
        </Slot>
    );
}

function CardBody({ animeId, active, badges, className }: { animeId: number; active: boolean; badges?: ReactNode; className?: string }) {
    const card = useAnimeCard(animeId, active);

    if (card.status === 'error') {
        return (
            <div className={cn('flex flex-col items-start gap-2 text-sm', className)} role="alert">
                <p className="text-muted-foreground">{card.message}</p>
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={card.retry}>
                        Try again
                    </Button>
                    <Button size="sm" variant="ghost" asChild>
                        <Link href={`/anime/${animeId}`}>Open the anime page</Link>
                    </Button>
                </div>
            </div>
        );
    }

    if (card.status !== 'ready') {
        return <CardSkeleton className={className} />;
    }

    return <CardContent data={card.data} badges={badges} className={className} />;
}

function CardSkeleton({ className }: { className?: string }) {
    return (
        <div className={cn('flex flex-col gap-3', className)} aria-busy="true" aria-label="Loading details">
            <div className="flex gap-3">
                <Skeleton className="aspect-[2/3] w-20 shrink-0 large:md:w-24" />
                <div className="flex flex-1 flex-col gap-2 pt-1">
                    <Skeleton className="h-4 w-4/5" />
                    <Skeleton className="h-3 w-3/5" />
                    <Skeleton className="h-3 w-full" />
                </div>
            </div>
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-full" />
            <Skeleton className="h-3 w-2/3" />
        </div>
    );
}

function CardContent({ data, badges, className }: { data: AnimeCardData; badges?: ReactNode; className?: string }) {
    const title = animeTitle(data);
    const subtitle = animeSubtitle(data);
    const description = useMemo(() => (data.description ? sanitizeAniListHtml(data.description) : null), [data.description]);
    const facts = [
        formatLabel(data.format),
        animeSeasonLabel(data.season, data.seasonYear),
        statusLabel(data.status),
        episodeCount(null, data.episodesTotal),
        data.durationMinutes ? `${data.durationMinutes} min` : null,
    ].filter(Boolean);
    const more = (
        <Link href={`/anime/${data.id}`} className="font-medium whitespace-nowrap text-foreground hover:underline">
            more
        </Link>
    );

    return (
        <div className={cn('flex flex-col gap-3 text-sm', className)}>
            <div className="flex gap-3">
                <Link href={`/anime/${data.id}`} className="shrink-0 self-start" tabIndex={-1} aria-hidden>
                    <ShowPoster {...coverPoster(data)} name={title} className="w-20 large:md:w-24" />
                </Link>
                <div className="flex min-w-0 flex-col gap-1">
                    <Link href={`/anime/${data.id}`} className="leading-tight font-medium break-words hover:underline large:md:text-base">
                        {title}
                    </Link>
                    {subtitle && <p className="text-xs break-words text-muted-foreground">{subtitle}</p>}
                    {facts.length > 0 && <p className="text-xs text-muted-foreground large:md:text-sm">{facts.join(' · ')}</p>}
                    {badges && <div className="pt-0.5">{badges}</div>}
                </div>
            </div>

            {description ? (
                <p className="leading-relaxed break-words text-muted-foreground">
                    {description}
                    {data.descriptionTruncated && '…'} {more}
                </p>
            ) : (
                <p className="text-muted-foreground">No description on AniList yet. {more}</p>
            )}

            {data.genres.length > 0 && (
                <div className="flex flex-wrap gap-1">
                    {data.genres.map((genre) => (
                        <span key={genre} className="rounded-full bg-secondary px-2 py-0.5 text-[11px] text-secondary-foreground large:md:text-xs">
                            {genre}
                        </span>
                    ))}
                </div>
            )}

            {data.linkedShow && (
                <Link
                    href={`/shows/${data.linkedShow.id}`}
                    className="flex min-w-0 items-center gap-1.5 border-t pt-2.5 text-xs text-muted-foreground hover:text-foreground large:md:text-sm"
                >
                    <Link2 className="size-3.5 shrink-0" aria-hidden />
                    <span className="truncate">{data.linkedShow.name}</span>
                    {data.linkedShow.isTracked && (
                        <span className="shrink-0 rounded-md border border-green-600/40 px-1 text-[10px] leading-4 font-medium text-green-700 dark:text-green-400">
                            tracked
                        </span>
                    )}
                </Link>
            )}
        </div>
    );
}
