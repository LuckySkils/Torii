import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { type FilterNavigation, type QueryParams } from '@/hooks/use-filter-navigation';
import { animeSeasonLabel, statusLabel } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type AdultFilter, type AnimeFilterOptions, type AnimeFilters } from '@/types/subtracker';
import { Loader2, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { FormatFilter, GenreFilter } from './facet-filters';

/** Radix Select rejects "" as an item value; this stands in for "any" and becomes an empty param. */
const ANY = 'any';

export function animeQueryParams(filters: AnimeFilters): QueryParams {
    // Always explicit: the backend treats a *missing* season/year as "current season",
    // and an *empty* one as "any".
    return {
        q: filters.q,
        season: filters.season ?? '',
        year: filters.year === null ? '' : String(filters.year),
        status: filters.status ?? '',
        linked: filters.linked,
        format: filters.format,
        genres_include: filters.genresInclude,
        genres_exclude: filters.genresExclude,
        adult: filters.adult,
    };
}

export const ANY_FILTERS: AnimeFilters = {
    q: '',
    season: null,
    year: null,
    status: null,
    linked: 'all',
    format: [],
    genresInclude: [],
    genresExclude: [],
    adult: 'hide',
};

interface AnimeToolbarProps {
    filters: AnimeFilters;
    options: AnimeFilterOptions;
    nav: FilterNavigation<AnimeFilters>;
}

export function AnimeToolbar({ filters, options, nav }: AnimeToolbarProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const set = nav.submit;

    const activeCount =
        [filters.season, filters.year, filters.status].filter((value) => value !== null).length +
        (filters.linked !== 'all' ? 1 : 0) +
        (filters.adult !== 'hide' ? 1 : 0) +
        filters.format.length +
        filters.genresInclude.length +
        filters.genresExclude.length;

    const selects = (fullWidth: boolean) => [
        <FilterSelect
            key="season"
            label="Season"
            fullWidth={fullWidth}
            widthClass="w-32"
            value={filters.season}
            anyLabel="All seasons"
            options={options.seasons.map((season) => ({ value: season, label: animeSeasonLabel(season, null) ?? season }))}
            onChange={(value) => set({ season: value as AnimeFilters['season'] })}
        />,
        <FilterSelect
            key="year"
            label="Year"
            fullWidth={fullWidth}
            widthClass="w-28"
            value={filters.year === null ? null : String(filters.year)}
            anyLabel="All years"
            options={options.years.map((year) => ({ value: String(year), label: String(year) }))}
            onChange={(value) => set({ year: value === null ? null : Number(value) })}
        />,
        <FilterSelect
            key="status"
            label="Status"
            fullWidth={fullWidth}
            widthClass="w-36"
            value={filters.status}
            anyLabel="Any status"
            options={options.statuses.map((status) => ({ value: status, label: statusLabel(status) ?? status }))}
            onChange={(value) => set({ status: value })}
        />,
        <FilterSelect
            key="linked"
            label="Linked"
            fullWidth={fullWidth}
            widthClass="w-36"
            value={filters.linked === 'all' ? null : filters.linked}
            anyLabel="Linked or not"
            options={[
                { value: 'yes', label: 'Linked to a show' },
                { value: 'no', label: 'Not linked' },
            ]}
            onChange={(value) => set({ linked: (value ?? 'all') as AnimeFilters['linked'] })}
        />,
        <FilterSelect
            key="adult"
            label="Adult titles"
            fullWidth={fullWidth}
            widthClass="w-36"
            value={filters.adult === 'hide' ? null : filters.adult}
            anyLabel="Hide adult"
            options={[
                { value: 'include', label: 'Include adult' },
                { value: 'only', label: 'Adult only' },
            ]}
            onChange={(value) => set({ adult: (value ?? 'hide') as AdultFilter })}
        />,
    ];

    const setGenres = (genresInclude: string[], genresExclude: string[]) => set({ genresInclude, genresExclude });

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center">
            <div className="relative w-full sm:w-56">
                <Input
                    placeholder="Search titles…"
                    value={nav.search}
                    onChange={(event) => nav.changeSearch(event.target.value)}
                    className={nav.searching ? 'pr-8' : undefined}
                    aria-label="Search anime titles"
                />
                {nav.searching && <Loader2 className="absolute top-1/2 right-2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
            </div>

            <div className="flex items-center gap-2 sm:hidden">
                <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
                    <SheetTrigger asChild>
                        <Button variant="outline" className="gap-1.5">
                            <SlidersHorizontal className="size-4" />
                            Filters
                            {activeCount > 0 && (
                                <span className="flex size-5 items-center justify-center rounded-full bg-primary text-xs text-primary-foreground">
                                    {activeCount}
                                </span>
                            )}
                        </Button>
                    </SheetTrigger>
                    <SheetContent side="bottom" className="max-h-[85vh] overflow-y-auto pb-[calc(1.5rem+env(safe-area-inset-bottom))]">
                        <SheetHeader>
                            <SheetTitle>Filters</SheetTitle>
                        </SheetHeader>
                        <div className="flex flex-col gap-4 py-4">
                            {selects(true)}
                            <div className="flex flex-col gap-1.5">
                                <span className="text-sm font-medium">Format</span>
                                <FormatFilter inline options={options.formats} selected={filters.format} onChange={(format) => set({ format })} />
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
                            <Button
                                variant="outline"
                                onClick={() => {
                                    nav.navigate(ANY_FILTERS);
                                    setSheetOpen(false);
                                }}
                            >
                                Clear filters
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>

            <div className="hidden flex-wrap items-center gap-2 sm:flex">
                {selects(false)}
                <FormatFilter className="w-36" options={options.formats} selected={filters.format} onChange={(format) => set({ format })} />
                <GenreFilter
                    className="w-40"
                    options={options.genres}
                    include={filters.genresInclude}
                    exclude={filters.genresExclude}
                    onChange={setGenres}
                />
            </div>
        </div>
    );
}

interface FilterSelectProps {
    label: string;
    value: string | null;
    anyLabel: string;
    options: { value: string; label: string }[];
    onChange: (value: string | null) => void;
    fullWidth: boolean;
    widthClass: string;
}

function FilterSelect({ label, value, anyLabel, options, onChange, fullWidth, widthClass }: FilterSelectProps) {
    const select = (
        <Select value={value ?? ANY} onValueChange={(next) => onChange(next === ANY ? null : next)}>
            <SelectTrigger className={cn(!fullWidth && widthClass)} aria-label={`Filter by ${label.toLowerCase()}`}>
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ANY}>{anyLabel}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );

    if (!fullWidth) {
        return select;
    }

    return (
        <div className="flex flex-col gap-1.5">
            <span className="text-sm font-medium">{label}</span>
            {select}
        </div>
    );
}
