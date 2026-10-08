import { Button } from '@/components/ui/button';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { Sheet, SheetClose, SheetContent, SheetDescription, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { patchCachedCard, useAnimeCard } from '@/hooks/use-anime-card';
import { animeSeasonLabel, animeSubtitle, animeTitle, coverPoster, episodeCount, formatLabel, statusLabel } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type AnimeCardData } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { Slot } from '@radix-ui/react-slot';
import { Link2 } from 'lucide-react';
import {
    createContext,
    useCallback,
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
import { CardPinContext } from './card-pin';
import { useIsTouch } from './hint';
import { ShowPoster } from './show-poster';
import { TrackSwitch } from './track-switch';

/** Long enough that scanning across the week board doesn't fire cards constantly. */
const OPEN_DELAY_MS = 350;
/** Time to move the pointer from the entry onto the card without it closing. */
const CLOSE_GRACE_MS = 150;

/** Set on touch devices: lets the entry's cover open the card instead of navigating. */
const TapToOpen = createContext<(() => void) | null>(null);

/** The desktop card: up to two thirds of the viewport, capped so it stays readable on very wide screens. */
const CARD_MAX_PX = 1024;
const cardWidth = () => Math.min(window.innerWidth * (2 / 3), CARD_MAX_PX);

interface Placement {
    side: 'right' | 'left' | 'top' | 'bottom';
    sideOffset: number;
}

/**
 * Beside the entry when the card fits there (right first, then left). Otherwise
 * over the entry itself: starting at its top edge (or ending at its bottom edge
 * when that leaves more room), so the card gets most of the window's height
 * rather than only the strip above or below the entry. Horizontally it can then
 * always shift to stay on screen.
 */
function placementFor(anchor: Element): Placement {
    const rect = anchor.getBoundingClientRect();
    const needed = cardWidth() + 16;

    if (window.innerWidth - rect.right >= needed) {
        return { side: 'right', sideOffset: 4 };
    }

    if (rect.left >= needed) {
        return { side: 'left', sideOffset: 4 };
    }

    return { side: window.innerHeight - rect.top >= rect.bottom ? 'bottom' : 'top', sideOffset: -rect.height };
}

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
}

/**
 * Detail card for one anime (GET /anime/{id}/card): a large delayed hover popover
 * with collision handling on desktop, a full-height bottom sheet opened from the
 * cover on touch.
 */
export function AnimeDetailCard({ animeId, badges, children }: AnimeDetailCardProps) {
    const touch = useIsTouch();
    const [open, setOpen] = useState(false);
    const [placement, setPlacement] = useState<Placement>({ side: 'right', sideOffset: 4 });
    const openTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const closeTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const contentRef = useRef<HTMLDivElement>(null);
    // Set while a dialog opened from inside the card (Track's confirm) is showing.
    const pinned = useRef(false);
    const pin = useCallback((value: boolean) => {
        pinned.current = value;
    }, []);

    useEffect(() => {
        return () => {
            clearTimeout(openTimer.current);
            clearTimeout(closeTimer.current);
        };
    }, []);

    function scheduleOpen(event: { currentTarget: Element }) {
        clearTimeout(closeTimer.current);

        if (!open) {
            const anchor = event.currentTarget;
            clearTimeout(openTimer.current);
            openTimer.current = setTimeout(() => {
                setPlacement(placementFor(anchor));
                setOpen(true);
            }, OPEN_DELAY_MS);
        }
    }

    function scheduleClose() {
        clearTimeout(openTimer.current);
        clearTimeout(closeTimer.current);
        closeTimer.current = setTimeout(() => !pinned.current && setOpen(false), CLOSE_GRACE_MS);
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
                    <SheetContent
                        side="bottom"
                        className="flex h-[100dvh] max-h-[100dvh] flex-col gap-0 p-0 pt-[env(safe-area-inset-top)] pb-[env(safe-area-inset-bottom)]"
                    >
                        <SheetTitle className="sr-only">Anime details</SheetTitle>
                        <SheetDescription className="sr-only">Description, genres and links for this anime.</SheetDescription>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            <CardBody animeId={animeId} active={open} badges={badges} layout="stack" className="p-4 pt-12" />
                        </div>
                        <div className="border-t p-4">
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
        <Popover open={open} onOpenChange={(next) => (next ? setOpen(true) : !pinned.current && closeNow())}>
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
                side={placement.side}
                sideOffset={placement.sideOffset}
                align={placement.side === 'left' || placement.side === 'right' ? 'start' : 'center'}
                collisionPadding={12}
                // Never taller than the space Radix measured, scrolling inside if the description runs long.
                className="max-h-[var(--radix-popover-content-available-height)] w-[min(66.67vw,64rem)] overflow-y-auto p-0"
                onOpenAutoFocus={(event) => event.preventDefault()}
                onCloseAutoFocus={(event) => event.preventDefault()}
                onMouseEnter={keepOpen}
                onMouseLeave={scheduleClose}
            >
                <CardPinContext.Provider value={pin}>
                    <CardBody animeId={animeId} active={open} badges={badges} layout="row" className="p-6 large:md:p-8" />
                </CardPinContext.Provider>
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

/** The linked SubsPlease show, with its Track switch (and the usual confirm) beside it. */
function LinkedShowLine({ animeId, show }: { animeId: number; show: NonNullable<AnimeCardData['linkedShow']> }) {
    return (
        <div className="flex min-w-0 items-center gap-3 border-t pt-4 text-base">
            <Link href={`/shows/${show.id}`} className="flex min-w-0 flex-1 items-center gap-2 text-muted-foreground hover:text-foreground">
                <Link2 className="size-5 shrink-0" aria-hidden />
                <span className="truncate">{show.name}</span>
            </Link>
            <label className="flex shrink-0 items-center gap-2 text-sm text-muted-foreground">
                {show.isTracked ? 'Tracked' : 'Track'}
                <TrackSwitch
                    showId={show.id}
                    showName={show.name}
                    tracked={show.isTracked}
                    onTracked={(isTracked) =>
                        patchCachedCard(animeId, (card) => ({ ...card, linkedShow: card.linkedShow && { ...card.linkedShow, isTracked } }))
                    }
                />
            </label>
        </div>
    );
}

/** `row`: cover on the left, text beside it (desktop popover). `stack`: cover on top (phone sheet). */
type Layout = 'row' | 'stack';

const COVER = { row: 'w-60 large:md:w-80', stack: 'mx-auto w-60' } as const;

interface CardBodyProps {
    animeId: number;
    active: boolean;
    badges?: ReactNode;
    layout: Layout;
    className?: string;
}

function CardBody({ animeId, active, badges, layout, className }: CardBodyProps) {
    const card = useAnimeCard(animeId, active);

    if (card.status === 'error') {
        return (
            <div className={cn('flex flex-col items-start gap-3 text-base', className)} role="alert">
                <p className="text-muted-foreground">{card.message}</p>
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" onClick={card.retry}>
                        Try again
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={`/anime/${animeId}`}>Open the anime page</Link>
                    </Button>
                </div>
            </div>
        );
    }

    if (card.status !== 'ready') {
        return <CardSkeleton layout={layout} className={className} />;
    }

    return <CardContent data={card.data} badges={badges} layout={layout} className={className} />;
}

function CardSkeleton({ layout, className }: { layout: Layout; className?: string }) {
    return (
        <div className={cn('flex gap-6', layout === 'stack' && 'flex-col', className)} aria-busy="true" aria-label="Loading details">
            <Skeleton className={cn('aspect-[2/3] shrink-0', COVER[layout])} />
            <div className="flex flex-1 flex-col gap-3 pt-1">
                <Skeleton className="h-8 w-4/5" />
                <Skeleton className="h-5 w-3/5" />
                <Skeleton className="h-5 w-2/5" />
                <Skeleton className="mt-3 h-5 w-full" />
                <Skeleton className="h-5 w-full" />
                <Skeleton className="h-5 w-full" />
                <Skeleton className="h-5 w-2/3" />
            </div>
        </div>
    );
}

function CardContent({ data, badges, layout, className }: { data: AnimeCardData; badges?: ReactNode; layout: Layout; className?: string }) {
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

    // Larger than list text: title ~1.7x, facts and description ~1.4x of the small card it replaced.
    return (
        <div className={cn('flex gap-6', layout === 'stack' && 'flex-col gap-5', className)}>
            <Link href={`/anime/${data.id}`} className={cn('shrink-0', layout === 'row' ? 'self-start' : 'self-center')} tabIndex={-1} aria-hidden>
                <ShowPoster {...coverPoster(data)} name={title} className={COVER[layout]} />
            </Link>

            <div className="flex min-w-0 flex-1 flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <Link href={`/anime/${data.id}`} className="text-xl leading-tight font-semibold break-words hover:underline sm:text-2xl">
                        {title}
                    </Link>
                    {subtitle && <p className="text-base break-words text-muted-foreground sm:text-lg">{subtitle}</p>}
                    {facts.length > 0 && <p className="text-base text-muted-foreground sm:text-lg">{facts.join(' · ')}</p>}
                </div>

                {/* The entry's own badges are tiny elsewhere; scaled up here rather than restyled. */}
                {badges && <div className="origin-top-left [zoom:1.5]">{badges}</div>}

                {description ? (
                    <p className="text-base leading-relaxed break-words text-muted-foreground sm:text-xl sm:leading-relaxed">
                        {description}
                        {data.descriptionTruncated && '…'} {more}
                    </p>
                ) : (
                    <p className="text-base text-muted-foreground sm:text-lg">No description on AniList yet. {more}</p>
                )}

                {data.genres.length > 0 && (
                    <div className="flex flex-wrap gap-2">
                        {data.genres.map((genre) => (
                            <span key={genre} className="rounded-full bg-secondary px-3 py-1 text-sm text-secondary-foreground sm:text-base">
                                {genre}
                            </span>
                        ))}
                    </div>
                )}

                {data.linkedShow && <LinkedShowLine animeId={data.id} show={data.linkedShow} />}
            </div>
        </div>
    );
}
