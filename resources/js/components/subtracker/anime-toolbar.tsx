import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { animeSeasonLabel, statusLabel } from '@/lib/anime';
import { cn } from '@/lib/utils';
import { type AnimeFilterOptions, type AnimeFilters } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

/** Radix Select rejects "" as an item value; this stands in for "any" and becomes an empty param. */
const ANY = 'any';

function toQueryParams(filters: AnimeFilters): Record<string, string> {
    // Always explicit: the backend treats a *missing* season/year as "current season",
    // and an *empty* one as "any".
    return {
        season: filters.season ?? '',
        year: filters.year === null ? '' : String(filters.year),
        status: filters.status ?? '',
        genre: filters.genre ?? '',
        linked: filters.linked,
    };
}

export function navigateAnime(filters: AnimeFilters) {
    router.get('/anime', toQueryParams(filters), { preserveState: true, preserveScroll: true, replace: true });
}

export const ANY_FILTERS: AnimeFilters = { season: null, year: null, status: null, genre: null, linked: 'all' };

interface AnimeToolbarProps {
    filters: AnimeFilters;
    options: AnimeFilterOptions;
}

export function AnimeToolbar({ filters, options }: AnimeToolbarProps) {
    const [sheetOpen, setSheetOpen] = useState(false);

    function set(overrides: Partial<AnimeFilters>) {
        navigateAnime({ ...filters, ...overrides });
    }

    const activeCount = [filters.season, filters.year, filters.status, filters.genre].filter((value) => value !== null).length + (filters.linked !== 'all' ? 1 : 0);

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
            key="genre"
            label="Genre"
            fullWidth={fullWidth}
            widthClass="w-36"
            value={filters.genre}
            anyLabel="All genres"
            options={options.genres.map((genre) => ({ value: genre, label: genre }))}
            onChange={(value) => set({ genre: value })}
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
    ];

    return (
        <>
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
                            <Button
                                variant="outline"
                                onClick={() => {
                                    navigateAnime(ANY_FILTERS);
                                    setSheetOpen(false);
                                }}
                            >
                                Clear filters
                            </Button>
                        </div>
                    </SheetContent>
                </Sheet>
            </div>

            <div className="hidden flex-wrap items-center gap-2 sm:flex">{selects(false)}</div>
        </>
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
