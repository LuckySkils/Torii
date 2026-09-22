import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { type ShowFilters } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { LayoutGrid, List, Loader2, SlidersHorizontal } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface ShowsToolbarProps {
    filters: ShowFilters;
    years: number[];
    view: 'grid' | 'list';
    onViewChange: (view: 'grid' | 'list') => void;
}

const SEARCH_DEBOUNCE_MS = 400;

/** Radix Select can't use "" as an item value, so "all"/"all-years" stand in for the unset filter on the wire. */
const SEASON_ALL = 'all';
const YEAR_ALL = 'all-years';

function toQueryParams(filters: ShowFilters): Record<string, string> {
    return {
        q: filters.q,
        tracked: filters.tracked,
        sort: filters.sort,
        season: filters.season ?? '',
        year: filters.year === null ? '' : String(filters.year),
    };
}

export function ShowsToolbar({ filters, years, view, onViewChange }: ShowsToolbarProps) {
    const [search, setSearch] = useState(filters.q);
    const [searching, setSearching] = useState(false);
    const [filtersOpen, setFiltersOpen] = useState(false);

    // Tracks the q value WE last sent to the server. If filters.q changes to
    // something else, it came from outside this component (clear-filters,
    // browser back/forward) and the input should resync; if it changes to
    // match this, it's just our own request landing and must not clobber
    // whatever the user has typed since.
    const lastSubmitted = useRef(filters.q);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const cancelTokenRef = useRef<{ cancel: VoidFunction } | null>(null);

    useEffect(() => {
        if (filters.q !== lastSubmitted.current) {
            lastSubmitted.current = filters.q;
            setSearch(filters.q);
        }
    }, [filters.q]);

    useEffect(() => {
        return () => clearTimeout(debounceRef.current);
    }, []);

    function navigate(next: ShowFilters, spinner: boolean) {
        cancelTokenRef.current?.cancel();
        lastSubmitted.current = next.q;

        router.get('/shows', toQueryParams(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (token) => {
                cancelTokenRef.current = token;
            },
            onStart: () => spinner && setSearching(true),
            onFinish: () => spinner && setSearching(false),
        });
    }

    function submit(overrides: Partial<ShowFilters>) {
        clearTimeout(debounceRef.current);
        navigate({ ...filters, q: search, ...overrides }, false);
    }

    function handleSearchChange(value: string) {
        setSearch(value);
        clearTimeout(debounceRef.current);

        debounceRef.current = setTimeout(() => {
            if (value === filters.q) {
                return;
            }

            navigate({ ...filters, q: value }, true);
        }, SEARCH_DEBOUNCE_MS);
    }

    function clearFiltersInSheet() {
        clearTimeout(debounceRef.current);
        navigate({ q: '', tracked: 'all', sort: filters.sort, season: null, year: null }, false);
        setFiltersOpen(false);
    }

    const activeFilterCount = (filters.tracked !== 'all' ? 1 : 0) + (filters.season !== null ? 1 : 0) + (filters.year !== null ? 1 : 0);

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
            <div className="relative w-full sm:w-auto sm:max-w-xs">
                <Input
                    placeholder="Search shows…"
                    value={search}
                    onChange={(e) => handleSearchChange(e.target.value)}
                    className={searching ? 'pr-8' : undefined}
                    aria-label="Search shows"
                />
                {searching && <Loader2 className="absolute top-1/2 right-2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
            </div>

            {/* Phone: filters collapse into a bottom sheet, view toggle stays next to it. */}
            <div className="flex items-center gap-2 sm:hidden">
                <Sheet open={filtersOpen} onOpenChange={setFiltersOpen}>
                    <SheetTrigger asChild>
                        <Button variant="outline" className="gap-1.5">
                            <SlidersHorizontal className="size-4" />
                            Filters
                            {activeFilterCount > 0 && (
                                <span className="flex size-5 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">
                                    {activeFilterCount}
                                </span>
                            )}
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto">
                        <SheetHeader>
                            <SheetTitle>Filters</SheetTitle>
                        </SheetHeader>
                        <div className="flex flex-col gap-4 py-4">
                            <div className="flex flex-col gap-1.5">
                                <label className="text-sm font-medium">Status</label>
                                <Select value={filters.tracked} onValueChange={(value) => submit({ tracked: value as ShowFilters['tracked'] })}>
                                    <SelectTrigger aria-label="Filter by tracked status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All shows</SelectItem>
                                        <SelectItem value="yes">Tracked</SelectItem>
                                        <SelectItem value="no">Untracked</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label className="text-sm font-medium">Season</label>
                                <Select
                                    value={filters.season ?? SEASON_ALL}
                                    onValueChange={(value) => submit({ season: value === SEASON_ALL ? null : (value as ShowFilters['season']) })}
                                >
                                    <SelectTrigger aria-label="Filter by season">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={SEASON_ALL}>All seasons</SelectItem>
                                        <SelectItem value="winter">Winter</SelectItem>
                                        <SelectItem value="spring">Spring</SelectItem>
                                        <SelectItem value="summer">Summer</SelectItem>
                                        <SelectItem value="autumn">Autumn</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label className="text-sm font-medium">Year</label>
                                <Select
                                    value={filters.year === null ? YEAR_ALL : String(filters.year)}
                                    onValueChange={(value) => submit({ year: value === YEAR_ALL ? null : Number(value) })}
                                >
                                    <SelectTrigger aria-label="Filter by year">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value={YEAR_ALL}>All years</SelectItem>
                                        {years.map((year) => (
                                            <SelectItem key={year} value={String(year)}>
                                                {year}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <label className="text-sm font-medium">Sort</label>
                                <Select value={filters.sort} onValueChange={(value) => submit({ sort: value as ShowFilters['sort'] })}>
                                    <SelectTrigger aria-label="Sort shows">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="name">Sort: name</SelectItem>
                                        <SelectItem value="last_seen">Sort: last seen</SelectItem>
                                        <SelectItem value="premiered">Premiere (newest)</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <Button variant="outline" onClick={clearFiltersInSheet}>
                                Clear filters
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>

                <ToggleGroup
                    type="single"
                    value={view}
                    onValueChange={(value) => value && onViewChange(value as 'grid' | 'list')}
                    aria-label="Shows view"
                >
                    <ToggleGroupItem value="grid" aria-label="Grid view">
                        <LayoutGrid className="size-4" />
                    </ToggleGroupItem>
                    <ToggleGroupItem value="list" aria-label="List view">
                        <List className="size-4" />
                    </ToggleGroupItem>
                </ToggleGroup>
            </div>

            {/* Tablet and up: the inline selects, unchanged. */}
            <Select value={filters.tracked} onValueChange={(value) => submit({ tracked: value as ShowFilters['tracked'] })}>
                <SelectTrigger className="hidden w-36 sm:flex" aria-label="Filter by tracked status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All shows</SelectItem>
                    <SelectItem value="yes">Tracked</SelectItem>
                    <SelectItem value="no">Untracked</SelectItem>
                </SelectContent>
            </Select>
            <Select
                value={filters.season ?? SEASON_ALL}
                onValueChange={(value) => submit({ season: value === SEASON_ALL ? null : (value as ShowFilters['season']) })}
            >
                <SelectTrigger className="hidden w-32 sm:flex" aria-label="Filter by season">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={SEASON_ALL}>All seasons</SelectItem>
                    <SelectItem value="winter">Winter</SelectItem>
                    <SelectItem value="spring">Spring</SelectItem>
                    <SelectItem value="summer">Summer</SelectItem>
                    <SelectItem value="autumn">Autumn</SelectItem>
                </SelectContent>
            </Select>
            <Select
                value={filters.year === null ? YEAR_ALL : String(filters.year)}
                onValueChange={(value) => submit({ year: value === YEAR_ALL ? null : Number(value) })}
            >
                <SelectTrigger className="hidden w-28 sm:flex" aria-label="Filter by year">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={YEAR_ALL}>All years</SelectItem>
                    {years.map((year) => (
                        <SelectItem key={year} value={String(year)}>
                            {year}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Select value={filters.sort} onValueChange={(value) => submit({ sort: value as ShowFilters['sort'] })}>
                <SelectTrigger className="hidden w-44 sm:flex" aria-label="Sort shows">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="name">Sort: name</SelectItem>
                    <SelectItem value="last_seen">Sort: last seen</SelectItem>
                    <SelectItem value="premiered">Premiere (newest)</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}
