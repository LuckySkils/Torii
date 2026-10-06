import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { type FilterNavigation, type QueryParams } from '@/hooks/use-filter-navigation';
import { cn } from '@/lib/utils';
import { type FilterOptions, type ShowFilters } from '@/types/subtracker';
import { LayoutGrid, List, Loader2, SlidersHorizontal } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { FormatFilter, GenreFilter } from './facet-filters';

interface ShowsToolbarProps {
    filters: ShowFilters;
    options: FilterOptions;
    nav: FilterNavigation<ShowFilters>;
    view: 'grid' | 'list';
    onViewChange: (view: 'grid' | 'list') => void;
    /** Extra controls for the phone row, after the view toggle (e.g. "Import"). */
    phoneActions?: ReactNode;
}

/** "Needs review · N" — a toggle for /shows?review=1, combinable with the other filters. */
function ReviewToggle({ active, count, onToggle, className }: { active: boolean; count: number; onToggle: () => void; className?: string }) {
    return (
        <Button
            type="button"
            variant="outline"
            aria-pressed={active}
            onClick={onToggle}
            className={cn(
                'gap-1.5',
                active && 'border-amber-500/60 bg-amber-500/10 text-amber-800 hover:bg-amber-500/15 dark:text-amber-200',
                className,
            )}
        >
            <span className={cn('size-1.5 rounded-full', active ? 'bg-amber-500' : 'bg-amber-500/70')} aria-hidden />
            Needs review
            <span className="text-muted-foreground tabular-nums">{count}</span>
        </Button>
    );
}

/** Radix Select can't use "" as an item value, so "all"/"all-years" stand in for the unset filter on the wire. */
const SEASON_ALL = 'all';
const YEAR_ALL = 'all-years';

export function showsQueryParams(filters: ShowFilters): QueryParams {
    return {
        q: filters.q,
        tracked: filters.tracked,
        sort: filters.sort,
        season: filters.season ?? '',
        year: filters.year === null ? '' : String(filters.year),
        review: filters.review ? '1' : '',
        format: filters.format,
        genres_include: filters.genresInclude,
        genres_exclude: filters.genresExclude,
    };
}

/** Everything off except the sort, which is a view preference rather than a filter. */
export function clearedShowFilters(sort: ShowFilters['sort']): ShowFilters {
    return { q: '', tracked: 'all', sort, season: null, year: null, review: false, format: [], genresInclude: [], genresExclude: [] };
}

export function ShowsToolbar({ filters, options, nav, view, onViewChange, phoneActions }: ShowsToolbarProps) {
    const [filtersOpen, setFiltersOpen] = useState(false);
    const submit = nav.submit;
    const reviewCount = options.reviewCount;
    const setGenres = (genresInclude: string[], genresExclude: string[]) => submit({ genresInclude, genresExclude });

    function clearFiltersInSheet() {
        nav.navigate(clearedShowFilters(filters.sort));
        setFiltersOpen(false);
    }

    const activeFilterCount =
        (filters.tracked !== 'all' ? 1 : 0) +
        (filters.season !== null ? 1 : 0) +
        (filters.year !== null ? 1 : 0) +
        (filters.review ? 1 : 0) +
        filters.format.length +
        filters.genresInclude.length +
        filters.genresExclude.length;

    return (
        <div className="flex w-full min-w-0 flex-col gap-2 sm:w-auto sm:flex-1 sm:flex-row sm:flex-wrap sm:items-center">
            <div className="relative w-full sm:w-auto sm:max-w-xs">
                <Input
                    placeholder="Search shows…"
                    value={nav.search}
                    onChange={(e) => nav.changeSearch(e.target.value)}
                    className={nav.searching ? 'pr-8' : undefined}
                    aria-label="Search shows"
                />
                {nav.searching && <Loader2 className="absolute top-1/2 right-2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
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
                                        {options.years.map((year) => (
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

                            <div className="flex flex-col gap-1.5">
                                <span className="text-sm font-medium">Format</span>
                                <FormatFilter inline options={options.formats} selected={filters.format} onChange={(format) => submit({ format })} />
                            </div>

                            <div className="flex flex-col gap-1.5">
                                <span className="text-sm font-medium">Genres</span>
                                <GenreFilter
                                    inline
                                    options={options.genres}
                                    include={filters.genresInclude}
                                    exclude={filters.genresExclude}
                                    onChange={setGenres}
                                />
                            </div>

                            {(reviewCount > 0 || filters.review) && (
                                <div className="flex flex-col gap-1.5">
                                    <label className="text-sm font-medium">Metadata</label>
                                    <ReviewToggle
                                        className="w-full"
                                        active={filters.review}
                                        count={reviewCount}
                                        onToggle={() => submit({ review: !filters.review })}
                                    />
                                </div>
                            )}

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
                {phoneActions}
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
                    {options.years.map((year) => (
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
            <FormatFilter
                className="hidden w-36 sm:flex"
                options={options.formats}
                selected={filters.format}
                onChange={(format) => submit({ format })}
            />
            <GenreFilter
                className="hidden w-40 sm:flex"
                options={options.genres}
                include={filters.genresInclude}
                exclude={filters.genresExclude}
                onChange={setGenres}
            />
            {(reviewCount > 0 || filters.review) && (
                <ReviewToggle
                    className="hidden sm:inline-flex"
                    active={filters.review}
                    count={reviewCount}
                    onToggle={() => submit({ review: !filters.review })}
                />
            )}
        </div>
    );
}
