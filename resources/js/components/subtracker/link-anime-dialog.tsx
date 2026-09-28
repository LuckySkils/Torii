import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useIsMobile } from '@/hooks/use-mobile';
import { animeSeasonLabel, animeSubtitle, animeTitle, coverPoster, episodeCount, formatLabel, ruleLabel, suggestionReasonText } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type LinkSearchResponse, type LinkSearchResult, type LinkSuggestion, type SuggestionReason } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { Info, Loader2, Search, TriangleAlert } from 'lucide-react';
import { type ReactNode, useEffect, useRef, useState } from 'react';
import { ShowPoster } from './show-poster';

export type LinkDialogTab = 'suggestions' | 'search';

interface LinkAnimeDialogProps {
    show: { id: number; name: string };
    open: boolean;
    onOpenChange: (open: boolean) => void;
    hasSuggestions: boolean;
    /** Defaults to Suggestions when there are any, else Search. */
    initialTab?: LinkDialogTab;
    /** Newest episode Torii has for the show, to explain episode-count mismatches. */
    currentEpisode?: number | null;
}

const SEARCH_DEBOUNCE_MS = 400;

type Load<T> = { status: 'loading' } | { status: 'error'; message: string } | { status: 'loaded'; data: T };

export function LinkAnimeDialog({ show, open, onOpenChange, hasSuggestions, initialTab, currentEpisode = null }: LinkAnimeDialogProps) {
    const isMobile = useIsMobile();
    const title = `Link "${show.name}"`;
    const description = 'Pick the AniList entry this SubsPlease show corresponds to.';
    const body = (
        <LinkAnimeBody
            key={open ? 'open' : 'closed'}
            show={show}
            initialTab={initialTab ?? (hasSuggestions ? 'suggestions' : 'search')}
            currentEpisode={currentEpisode}
            onLinked={() => onOpenChange(false)}
        />
    );

    if (isMobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent side="bottom" className="flex max-h-[90svh] flex-col gap-3 rounded-t-xl p-4 pb-[calc(1rem+env(safe-area-inset-bottom))]">
                    <SheetHeader className="pr-8 text-left">
                        <SheetTitle className="break-words">{title}</SheetTitle>
                        <SheetDescription>{description}</SheetDescription>
                    </SheetHeader>
                    {body}
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[85vh] flex-col gap-3 sm:max-w-2xl">
                <DialogHeader className="pr-6">
                    <DialogTitle className="break-words">{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                {body}
            </DialogContent>
        </Dialog>
    );
}

function LinkAnimeBody({
    show,
    initialTab,
    currentEpisode,
    onLinked,
}: {
    show: { id: number; name: string };
    initialTab: LinkDialogTab;
    currentEpisode: number | null;
    onLinked: () => void;
}) {
    const [tab, setTab] = useState<LinkDialogTab>(initialTab);
    const [linkingId, setLinkingId] = useState<number | null>(null);

    function link(animeId: number) {
        setLinkingId(animeId);
        router.post(
            `/shows/${show.id}/link`,
            { anime_id: animeId },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: onLinked,
                onFinish: () => setLinkingId(null),
            },
        );
    }

    return (
        <Tabs value={tab} onValueChange={(value) => setTab(value as LinkDialogTab)} className="flex min-h-0 flex-1 flex-col gap-3">
            <TabsList className="grid w-full grid-cols-2">
                <TabsTrigger value="suggestions">Suggestions</TabsTrigger>
                <TabsTrigger value="search">Search</TabsTrigger>
            </TabsList>
            <TabsContent value="suggestions" className="mt-0 min-h-0 flex-1 overflow-y-auto">
                <SuggestionsPanel
                    showId={show.id}
                    currentEpisode={currentEpisode}
                    linkingId={linkingId}
                    onLink={link}
                    onSearchInstead={() => setTab('search')}
                />
            </TabsContent>
            <TabsContent value="search" className="mt-0 flex min-h-0 flex-1 flex-col gap-3">
                <SearchPanel show={show} linkingId={linkingId} onLink={link} />
            </TabsContent>
        </Tabs>
    );
}

function SuggestionsPanel({
    showId,
    currentEpisode,
    linkingId,
    onLink,
    onSearchInstead,
}: {
    showId: number;
    currentEpisode: number | null;
    linkingId: number | null;
    onLink: (animeId: number) => void;
    onSearchInstead: () => void;
}) {
    const [state, setState] = useState<Load<LinkSuggestion[]>>({ status: 'loading' });
    const [rejectingId, setRejectingId] = useState<number | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        fetch(`/shows/${showId}/link/suggestions`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Request failed (${response.status})`);
                }

                const json: { suggestions: LinkSuggestion[] } = await response.json();
                setState({ status: 'loaded', data: json.suggestions });
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setState({ status: 'error', message: error instanceof Error ? error.message : 'Failed to load suggestions' });
                }
            });

        return () => controller.abort();
    }, [showId]);

    function reject(animeId: number) {
        setRejectingId(animeId);
        router.delete(`/shows/${showId}/link/suggestions/${animeId}`, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                setState((current) => (current.status === 'loaded' ? { status: 'loaded', data: current.data.filter((row) => row.id !== animeId) } : current)),
            onFinish: () => setRejectingId(null),
        });
    }

    if (state.status === 'loading') {
        return <ResultSkeletons />;
    }

    if (state.status === 'error') {
        return <PanelMessage tone="error">Couldn't load suggestions: {state.message}</PanelMessage>;
    }

    if (state.data.length === 0) {
        return (
            <PanelMessage>
                No suggestions left.{' '}
                <button type="button" className="cursor-pointer font-medium text-foreground hover:underline" onClick={onSearchInstead}>
                    Search instead
                </button>
            </PanelMessage>
        );
    }

    return (
        <div className="flex flex-col gap-2">
            <p className="text-xs text-muted-foreground">Automatic matching found these but didn't link them. "Not this one" is remembered: it won't be suggested again.</p>
            {state.data.map((row) => (
                <ResultRow
                    key={row.id}
                    row={row}
                    detail={
                        <>
                            score {row.score} · {ruleLabel(row.rule)}
                        </>
                    }
                    note={<SuggestionReasonNote reason={row.reason} text={suggestionReasonText(row.reason, row.episodesTotal, currentEpisode)} />}
                    actions={
                        <>
                            <Button size="sm" disabled={linkingId !== null || rejectingId !== null} onClick={() => onLink(row.id)}>
                                {linkingId === row.id ? 'Linking…' : 'Link'}
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={linkingId !== null || rejectingId !== null}
                                onClick={() => reject(row.id)}
                            >
                                {rejectingId === row.id ? 'Removing…' : 'Not this one'}
                            </Button>
                        </>
                    }
                />
            ))}
        </div>
    );
}

function SearchPanel({ show, linkingId, onLink }: { show: { id: number; name: string }; linkingId: number | null; onLink: (animeId: number) => void }) {
    const [query, setQuery] = useState(show.name);
    const [state, setState] = useState<Load<LinkSearchResponse>>({ status: 'loading' });
    const [searching, setSearching] = useState(true);
    const controllerRef = useRef<AbortController | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    function runSearch(q: string) {
        // A newer search always wins: the previous request is aborted, so its
        // response can never overwrite these results.
        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;
        setSearching(true);

        fetch(`/shows/${show.id}/link/search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (response) => {
                if (!response.ok) {
                    throw new Error(`Request failed (${response.status})`);
                }

                const json: LinkSearchResponse = await response.json();
                setState({ status: 'loaded', data: json });
                setSearching(false);
            })
            .catch((error: unknown) => {
                if (controller.signal.aborted) {
                    return;
                }

                setState({ status: 'error', message: error instanceof Error ? error.message : 'Search failed' });
                setSearching(false);
            });
    }

    // The prefilled name is sent as an *empty* q: only then does the backend strip
    // season markers ("Link Click S3" → "Link Click") before asking AniList.
    const untouched = (value: string) => value.trim() === '' || value.trim() === show.name;

    useEffect(() => {
        runSearch('');

        return () => {
            controllerRef.current?.abort();
            clearTimeout(debounceRef.current);
        };
        // Only the initial, prefilled search; typing schedules its own.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [show.id]);

    function handleChange(value: string) {
        setQuery(value);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => runSearch(untouched(value) ? '' : value), SEARCH_DEBOUNCE_MS);
    }

    return (
        <>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input value={query} onChange={(e) => handleChange(e.target.value)} className="px-9" aria-label="Search AniList titles" />
                {searching && <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto">
                {state.status === 'loading' ? (
                    <ResultSkeletons />
                ) : state.status === 'error' ? (
                    <PanelMessage tone="error">Search failed: {state.message}</PanelMessage>
                ) : (
                    <div className="flex flex-col gap-2">
                        {state.data.searchedQuery.trim().toLowerCase() !== state.data.query.trim().toLowerCase() && (
                            <p className="text-xs text-muted-foreground">
                                Searched AniList for "{state.data.searchedQuery}", without the season marker, so other seasons show up too.
                            </p>
                        )}
                        {state.data.providerError && (
                            <p className="rounded-md bg-muted px-3 py-2 text-xs text-muted-foreground">
                                AniList couldn't be reached right now, so these are only titles Torii already knows. Try again in a minute.
                            </p>
                        )}
                        {state.data.results.length === 0 ? (
                            <PanelMessage>No titles match "{state.data.searchedQuery}". Try the English or Japanese title, or fewer words.</PanelMessage>
                        ) : (
                            state.data.results.map((row) => (
                                <ResultRow
                                    key={row.id}
                                    row={row}
                                    detail={<>match score {row.score}</>}
                                    actions={
                                        <Button size="sm" disabled={linkingId !== null} onClick={() => onLink(row.id)}>
                                            {linkingId === row.id ? 'Linking…' : 'Link'}
                                        </Button>
                                    }
                                />
                            ))
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

/**
 * A suggestion's reason, readable rather than a tag. `episode_count` is the one
 * the backend actively distrusts, so it's set apart from the neutral ones.
 */
function SuggestionReasonNote({ reason, text }: { reason: SuggestionReason; text: string }) {
    const distrusted = reason === 'episode_count';

    return (
        <p
            className={cn(
                'flex gap-1.5 rounded-md px-2 py-1.5 text-xs leading-snug',
                distrusted ? 'bg-amber-500/10 text-amber-800 dark:text-amber-200' : 'bg-muted text-muted-foreground',
            )}
        >
            {distrusted ? <TriangleAlert className="mt-px size-3.5 shrink-0" /> : <Info className="mt-px size-3.5 shrink-0" />}
            <span>{text}</span>
        </p>
    );
}

function ResultRow({ row, detail, actions, note }: { row: LinkSearchResult; detail: ReactNode; actions: ReactNode; note?: ReactNode }) {
    const facts = [formatLabel(row.format), animeSeasonLabel(row.season, row.seasonYear), episodeCount(null, row.episodesTotal)].filter(Boolean);
    const subtitle = animeSubtitle(row);

    return (
        <div className="flex gap-3 rounded-lg border p-2.5">
            <ShowPoster {...coverPoster(row)} name={animeTitle(row)} className="w-12 shrink-0 self-start sm:w-14" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <p className="text-sm leading-tight font-medium break-words">{animeTitle(row)}</p>
                {subtitle && <p className="text-xs break-words text-muted-foreground">{subtitle}</p>}
                {facts.length > 0 && <p className="text-xs text-muted-foreground">{facts.join(' · ')}</p>}
                <div className="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                    <Badge variant="outline" className="rounded-md px-1.5 py-0 font-normal">
                        {detail}
                    </Badge>
                    {row.linkedShows.length > 0 && <span>also linked to {row.linkedShows.map((linked) => linked.name).join(', ')}</span>}
                </div>
                {note}
                <div className="flex flex-wrap gap-2 pt-1">{actions}</div>
            </div>
        </div>
    );
}

function ResultSkeletons() {
    return (
        <div className="flex flex-col gap-2" aria-label="Loading">
            {[0, 1, 2].map((index) => (
                <div key={index} className="flex gap-3 rounded-lg border p-2.5">
                    <Skeleton className="aspect-[2/3] w-12 shrink-0 sm:w-14" />
                    <div className="flex flex-1 flex-col gap-2 pt-1">
                        <Skeleton className="h-4 w-3/4" />
                        <Skeleton className="h-3 w-1/2" />
                        <Skeleton className="h-3 w-1/3" />
                    </div>
                </div>
            ))}
        </div>
    );
}

function PanelMessage({ children, tone = 'muted' }: { children: ReactNode; tone?: 'muted' | 'error' }) {
    return <p className={tone === 'error' ? 'p-4 text-sm text-destructive' : 'p-4 text-center text-sm text-muted-foreground'}>{children}</p>;
}
