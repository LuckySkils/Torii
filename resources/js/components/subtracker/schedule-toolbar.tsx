import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { animeSeasonLabel } from '@/lib/anime';
import { type ScheduleState } from '@/lib/schedule';
import { type AdultFilter, type ScheduleFilterOptions } from '@/types/subtracker';
import { SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import { FilterSelect } from './anime-toolbar';
import { FormatFilter } from './facet-filters';

interface ScheduleToolbarProps {
    filters: ScheduleState;
    options: ScheduleFilterOptions;
    onChange: (overrides: Partial<ScheduleState>) => void;
    onClear: () => void;
}

/** Number of filters in the row (Mine only sits next to the tabs and isn't counted here). */
export function activeScheduleFilters(filters: ScheduleState): number {
    return (
        (filters.season !== null ? 1 : 0) +
        (filters.year !== null ? 1 : 0) +
        (filters.linked !== 'all' ? 1 : 0) +
        (filters.adult !== 'hide' ? 1 : 0) +
        filters.format.length
    );
}

/** Season, year, format, linked and adult: inline from `sm`, in a bottom sheet on phones. */
export function ScheduleToolbar({ filters, options, onChange, onClear }: ScheduleToolbarProps) {
    const [sheetOpen, setSheetOpen] = useState(false);
    const activeCount = activeScheduleFilters(filters);

    const selects = (fullWidth: boolean) => [
        <FilterSelect
            key="season"
            label="Season"
            fullWidth={fullWidth}
            widthClass="w-32"
            value={filters.season}
            anyLabel="All seasons"
            options={options.seasons.map((season) => ({ value: season, label: animeSeasonLabel(season, null) ?? season }))}
            onChange={(value) => onChange({ season: value as ScheduleState['season'] })}
        />,
        <FilterSelect
            key="year"
            label="Year"
            fullWidth={fullWidth}
            widthClass="w-28"
            value={filters.year === null ? null : String(filters.year)}
            anyLabel="All years"
            options={options.years.map((year) => ({ value: String(year), label: String(year) }))}
            onChange={(value) => onChange({ year: value === null ? null : Number(value) })}
        />,
        <FilterSelect
            key="linked"
            label="Linked"
            fullWidth={fullWidth}
            widthClass="w-36"
            value={filters.linked === 'all' ? null : filters.linked}
            anyLabel="Linked or not"
            options={[
                { value: 'linked', label: 'Linked to a show' },
                { value: 'unlinked', label: 'Not linked' },
            ]}
            onChange={(value) => onChange({ linked: (value ?? 'all') as ScheduleState['linked'] })}
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
            onChange={(value) => onChange({ adult: (value ?? 'hide') as AdultFilter })}
        />,
    ];

    return (
        <>
            <div className="sm:hidden">
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
                                <FormatFilter
                                    inline
                                    options={options.formats}
                                    selected={filters.format}
                                    onChange={(format) => onChange({ format })}
                                />
                            </div>
                            <Button
                                variant="outline"
                                onClick={() => {
                                    onClear();
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
                <FormatFilter className="w-36" options={options.formats} selected={filters.format} onChange={(format) => onChange({ format })} />
                {(activeCount > 0 || filters.tracked !== 'all') && (
                    <Button variant="ghost" size="sm" onClick={onClear}>
                        Clear filters
                    </Button>
                )}
            </div>
        </>
    );
}
