import { NowLine, ScheduleCard, ScheduleRow } from '@/components/subtracker/schedule-entry';
import { activeScheduleFilters, ScheduleToolbar } from '@/components/subtracker/schedule-toolbar';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Toggle } from '@/components/ui/toggle';
import { useAdaptivePoll } from '@/hooks/use-adaptive-poll';
import { useFilterNavigation } from '@/hooks/use-filter-navigation';
import AppLayout from '@/layouts/app-layout';
import {
    addLocalDays,
    clearSavedSchedule,
    dayMonth,
    DEFAULT_SCHEDULE_FILTERS,
    groupByLocalDay,
    isMine,
    loadSavedSchedule,
    localDayKey,
    longDay,
    rangeFor,
    restoredState,
    sameInstant,
    saveSchedule,
    scheduleQueryParams,
    shortWeekday,
    startOfLocalDay,
    startOfLocalWeek,
    urlParamKeys,
    viewFromUrl,
    type ScheduleState,
    type ScheduleView,
} from '@/lib/schedule';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type ScheduleAiring, type ScheduleProps } from '@/types/subtracker';
import { Head, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, UserCheck } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Schedule', href: '/schedule' }];

/** Re-renders every minute, so "now", past dimming and "today" keep up with the clock. */
function useNow(intervalMs = 60_000): Date {
    const [now, setNow] = useState(() => new Date());

    useEffect(() => {
        const id = setInterval(() => setNow(new Date()), intervalMs);

        return () => clearInterval(id);
    }, [intervalMs]);

    return now;
}

/** Where the "now" line goes: before the first airing still to come. */
function nowIndex(airings: ScheduleAiring[], now: Date): number {
    const index = airings.findIndex((airing) => new Date(airing.airsAt) > now);

    return index === -1 ? airings.length : index;
}

export default function ScheduleIndex({ airings, filters, filterOptions }: ScheduleProps) {
    const { url } = usePage();
    const urlKeys = useMemo(() => urlParamKeys(url), [url]);
    const urlView = viewFromUrl(url);
    // A URL with a range but no view (hand-edited) is read by its length.
    const rangeDays = (new Date(filters.to).getTime() - new Date(filters.from).getTime()) / 86_400_000;
    const view: ScheduleView = urlView ?? (urlKeys.has('from') && rangeDays > 1.5 ? 'week' : 'today');
    // Everything the page shows must be in the URL; until then it redirects instead of rendering.
    const complete = urlKeys.has('from') && urlKeys.has('to') && urlView !== null;

    const serverState = useMemo<ScheduleState>(() => ({ ...filters, view }), [filters, view]);
    const nav = useFilterNavigation('/schedule', serverState, scheduleQueryParams);
    const state = nav.filters;
    const now = useNow();

    useAdaptivePoll(5 * 60_000, ['airings']);

    // B2. A plain visit applies the saved filters; a URL with parameters wins and only
    // gets its missing view or range filled in (from the user's local clock).
    const booted = useRef(false);
    useEffect(() => {
        if (booted.current || complete) {
            return;
        }

        const next: ScheduleState =
            urlKeys.size === 0
                ? (() => {
                      const restored = restoredState(loadSavedSchedule());

                      return { ...filters, ...restored, ...rangeFor(restored.view, new Date()) };
                  })()
                : { ...filters, view, ...(urlKeys.has('from') && urlKeys.has('to') ? { from: filters.from, to: filters.to } : rangeFor(view, new Date())) };

        // One tick later: on first load this effect runs before Inertia's own App effect
        // has initialised the router, and a visit started now would throw.
        const timer = setTimeout(() => {
            booted.current = true;
            nav.navigate(next);
        });

        return () => clearTimeout(timer);
        // Runs once, on the first render that lacks a complete URL.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [complete]);

    useEffect(() => {
        if (complete) {
            saveSchedule(serverState);
        }
    }, [complete, serverState]);

    function setView(next: ScheduleView) {
        nav.submit({ view: next, ...rangeFor(next, new Date()) });
    }

    function shiftWeek(weeks: number) {
        const start = addLocalDays(startOfLocalWeek(new Date(state.from)), weeks * 7);
        nav.navigate({ ...state, ...rangeFor('week', start) }, { push: true });
    }

    function goToCurrent() {
        nav.navigate({ ...state, ...rangeFor(state.view, new Date()) }, { push: true });
    }

    function clearFilters() {
        clearSavedSchedule();
        nav.submit({ ...DEFAULT_SCHEDULE_FILTERS });
    }

    const filtered = activeScheduleFilters(state) > 0 || state.tracked === 'tracked';
    const days = groupByLocalDay(airings, filters.from, filters.to);
    const isCurrent = sameInstant(filters.from, view === 'week' ? startOfLocalWeek(now) : startOfLocalDay(now));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Schedule" />
            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Tabs value={state.view} onValueChange={(value) => setView(value as ScheduleView)}>
                        <TabsList>
                            <TabsTrigger value="today">Today</TabsTrigger>
                            <TabsTrigger value="week">Week</TabsTrigger>
                        </TabsList>
                    </Tabs>
                    <Toggle
                        variant="outline"
                        pressed={state.tracked === 'tracked'}
                        onPressedChange={(pressed) => nav.submit({ tracked: pressed ? 'tracked' : 'all' })}
                        aria-label="Only shows I track"
                        className="gap-1.5 data-[state=on]:border-green-600/50 data-[state=on]:bg-green-600/10 data-[state=on]:text-green-800 dark:data-[state=on]:text-green-300"
                    >
                        <UserCheck />
                        Mine only
                    </Toggle>
                    <div className="sm:ml-auto">
                        <ScheduleToolbar filters={state} options={filterOptions} onChange={nav.submit} onClear={clearFilters} />
                    </div>
                </div>

                {!complete ? (
                    <ScheduleSkeleton />
                ) : view === 'today' ? (
                    <TodayView
                        day={days[0]}
                        now={now}
                        isCurrent={isCurrent}
                        filtered={filtered}
                        mineOnly={state.tracked === 'tracked'}
                        onToday={goToCurrent}
                        onWeek={() => setView('week')}
                        onClear={clearFilters}
                        onEveryone={() => nav.submit({ tracked: 'all' })}
                    />
                ) : (
                    <WeekView
                        days={days}
                        now={now}
                        isCurrent={isCurrent}
                        filtered={filtered}
                        mineOnly={state.tracked === 'tracked'}
                        onShift={shiftWeek}
                        onThisWeek={goToCurrent}
                        onClear={clearFilters}
                        onEveryone={() => nav.submit({ tracked: 'all' })}
                    />
                )}
            </div>
        </AppLayout>
    );
}

interface EmptyActions {
    filtered: boolean;
    mineOnly: boolean;
    onClear: () => void;
    onEveryone: () => void;
}

function EmptyState({ message, children }: { message: string; children?: React.ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border p-8 text-center text-sm text-muted-foreground">
            <p>{message}</p>
            {children && <div className="flex flex-wrap justify-center gap-2">{children}</div>}
        </div>
    );
}

function FilterWayOut({ mineOnly, onClear, onEveryone }: Omit<EmptyActions, 'filtered'>) {
    return (
        <>
            {mineOnly && (
                <Button variant="outline" size="sm" onClick={onEveryone}>
                    Show everyone's
                </Button>
            )}
            <Button variant="outline" size="sm" onClick={onClear}>
                Clear filters
            </Button>
        </>
    );
}

interface TodayViewProps extends EmptyActions {
    day: { day: Date; airings: ScheduleAiring[] } | undefined;
    now: Date;
    isCurrent: boolean;
    onToday: () => void;
    onWeek: () => void;
}

function TodayView({ day, now, isCurrent, filtered, mineOnly, onToday, onWeek, onClear, onEveryone }: TodayViewProps) {
    const entries = day?.airings ?? [];
    const mine = entries.filter(isMine).length;
    const split = isCurrent ? nowIndex(entries, now) : -1;

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <h2 className="text-lg font-medium first-letter:uppercase large:md:text-2xl">{day ? longDay.format(day.day) : 'Today'}</h2>
                <p className="text-sm text-muted-foreground large:md:text-base">
                    {entries.length} episode{entries.length === 1 ? '' : 's'}
                    {isCurrent ? ' today' : ''}
                    {mine > 0 && !mineOnly ? `, ${mine} yours` : ''}
                </p>
                {!isCurrent && (
                    <Button variant="outline" size="sm" onClick={onToday}>
                        Go to today
                    </Button>
                )}
            </div>

            {entries.length === 0 ? (
                filtered ? (
                    <EmptyState message="Nothing today matches these filters.">
                        <FilterWayOut mineOnly={mineOnly} onClear={onClear} onEveryone={onEveryone} />
                    </EmptyState>
                ) : (
                    <EmptyState message="No episodes scheduled today.">
                        <Button variant="outline" size="sm" onClick={onWeek}>
                            See the week
                        </Button>
                    </EmptyState>
                )
            ) : (
                <ol className="divide-y rounded-xl border large:md:max-w-5xl">
                    {entries.map((airing, index) => (
                        <TodayEntry key={`${airing.anime.id}-${airing.episode}`} airing={airing} now={now} showNowLine={index === split} />
                    ))}
                    {split === entries.length && <NowLine now={now} className="px-3 py-1.5" />}
                </ol>
            )}
        </section>
    );
}

function TodayEntry({ airing, now, showNowLine }: { airing: ScheduleAiring; now: Date; showNowLine: boolean }) {
    return (
        <>
            {showNowLine && <NowLine now={now} className="px-3 py-1.5" />}
            <ScheduleRow airing={airing} past={new Date(airing.airsAt) <= now} />
        </>
    );
}

interface WeekViewProps extends EmptyActions {
    days: { day: Date; airings: ScheduleAiring[] }[];
    now: Date;
    isCurrent: boolean;
    onShift: (weeks: number) => void;
    onThisWeek: () => void;
}

function WeekView({ days, now, isCurrent, filtered, mineOnly, onShift, onThisWeek, onClear, onEveryone }: WeekViewProps) {
    const total = days.reduce((sum, day) => sum + day.airings.length, 0);
    const mine = days.reduce((sum, day) => sum + day.airings.filter(isMine).length, 0);
    const first = days[0]?.day;
    const last = days.at(-1)?.day;

    return (
        <section className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="flex items-center gap-1">
                    <Button variant="outline" size="icon" className="size-9" onClick={() => onShift(-1)} aria-label="Previous week">
                        <ChevronLeft className="size-4" />
                    </Button>
                    <Button variant="outline" size="icon" className="size-9" onClick={() => onShift(1)} aria-label="Next week">
                        <ChevronRight className="size-4" />
                    </Button>
                </div>
                <h2 className="text-lg font-medium large:md:text-2xl">
                    {first && last ? `${dayMonth.format(first)} – ${dayMonth.format(last)}` : 'Week'}
                </h2>
                <span className="text-sm text-muted-foreground large:md:text-base">
                    {total} episode{total === 1 ? '' : 's'}
                    {mine > 0 && !mineOnly ? `, ${mine} yours` : ''}
                </span>
                {!isCurrent && (
                    <Button variant="outline" size="sm" className="ml-auto" onClick={onThisWeek}>
                        This week
                    </Button>
                )}
            </div>

            {total === 0 ? (
                filtered ? (
                    <EmptyState message="Nothing this week matches these filters.">
                        <FilterWayOut mineOnly={mineOnly} onClear={onClear} onEveryone={onEveryone} />
                    </EmptyState>
                ) : (
                    <EmptyState message="Nothing scheduled this week. Air dates come from the daily AniList sync.">
                        {!isCurrent && (
                            <Button variant="outline" size="sm" onClick={onThisWeek}>
                                Go to this week
                            </Button>
                        )}
                    </EmptyState>
                )
            ) : (
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7 large:md:gap-4">
                    {days.map(({ day, airings }) => (
                        <DayColumn key={localDayKey(day)} day={day} airings={airings} now={now} />
                    ))}
                </div>
            )}
        </section>
    );
}

/** One day of the board: sticky header (today tinted), then time-ordered cards or a placeholder. */
function DayColumn({ day, airings, now }: { day: Date; airings: ScheduleAiring[]; now: Date }) {
    const isToday = localDayKey(day) === localDayKey(now);
    const split = isToday ? nowIndex(airings, now) : -1;

    return (
        <section
            className={cn('flex min-w-0 flex-col rounded-xl border', isToday && 'border-sky-500/70 ring-1 ring-sky-500/30')}
            aria-label={longDay.format(day)}
            aria-current={isToday ? 'date' : undefined}
        >
            <header
                className={cn(
                    'sticky top-0 z-10 flex items-baseline justify-between gap-2 rounded-t-xl border-b px-2.5 py-2 large:md:px-3 large:md:py-2.5',
                    isToday ? 'border-sky-500/40 bg-sky-50 dark:bg-sky-950' : 'bg-background',
                )}
            >
                <span className="flex items-baseline gap-1.5 text-sm font-medium large:md:text-base">
                    <span className="first-letter:uppercase">{shortWeekday.format(day)}</span>
                    <span className="tabular-nums">{day.getDate()}</span>
                    {isToday && <span className="text-xs font-normal text-sky-700 dark:text-sky-300">today</span>}
                </span>
                <span className="text-xs text-muted-foreground tabular-nums large:md:text-sm" aria-label={`${airings.length} episodes`}>
                    {airings.length}
                </span>
            </header>
            <ol className="flex flex-col gap-1.5 p-1.5 large:md:gap-2 large:md:p-2">
                {airings.length === 0 ? (
                    <li className="rounded-lg border border-dashed px-2 py-4 text-center text-xs text-muted-foreground">nothing scheduled</li>
                ) : (
                    airings.map((airing, index) => (
                        <DayEntry key={`${airing.anime.id}-${airing.episode}`} airing={airing} now={now} showNowLine={index === split} />
                    ))
                )}
                {airings.length > 0 && split === airings.length && <NowLine now={now} className="py-0.5" />}
            </ol>
        </section>
    );
}

function DayEntry({ airing, now, showNowLine }: { airing: ScheduleAiring; now: Date; showNowLine: boolean }) {
    return (
        <>
            {showNowLine && <NowLine now={now} className="py-0.5" />}
            <ScheduleCard airing={airing} past={new Date(airing.airsAt) <= now} />
        </>
    );
}

function ScheduleSkeleton() {
    return (
        <div className="flex flex-col gap-2" aria-busy="true" aria-label="Loading schedule">
            <Skeleton className="h-7 w-56" />
            {Array.from({ length: 5 }, (_, index) => (
                <Skeleton key={index} className="h-16 w-full" />
            ))}
        </div>
    );
}
