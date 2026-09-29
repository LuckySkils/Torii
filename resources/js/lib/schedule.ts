import { type QueryParams } from '@/hooks/use-filter-navigation';
import { type ScheduleAiring, type ScheduleFilters } from '@/types/subtracker';

export type ScheduleView = 'today' | 'week';

/** What the page navigates with: the server's filters plus the Today/Week choice. */
export type ScheduleState = ScheduleFilters & { view: ScheduleView };

/** Everything but the range: what "Clear filters" resets to. */
export const DEFAULT_SCHEDULE_FILTERS: Omit<ScheduleFilters, 'from' | 'to'> = {
    season: null,
    year: null,
    format: [],
    linked: 'all',
    tracked: 'all',
    adult: 'hide',
};

/* ------------------------------------------------------------------ ranges */

/** Midnight at the start of `date`'s day, in the browser's timezone. */
export function startOfLocalDay(date: Date): Date {
    const day = new Date(date);
    day.setHours(0, 0, 0, 0);

    return day;
}

/** Calendar days, not 24h steps, so DST changes keep days aligned with the clock. */
export function addLocalDays(date: Date, days: number): Date {
    const next = new Date(date);
    next.setDate(next.getDate() + days);

    return next;
}

/** Monday 00:00 local of the week containing `date`. */
export function startOfLocalWeek(date: Date): Date {
    const day = startOfLocalDay(date);
    const sinceMonday = (day.getDay() + 6) % 7;

    return addLocalDays(day, -sinceMonday);
}

/** [from, to) for the view, as UTC ISO strings the backend applies directly. */
export function rangeFor(view: ScheduleView, anchor: Date): { from: string; to: string } {
    const from = view === 'week' ? startOfLocalWeek(anchor) : startOfLocalDay(anchor);
    const to = addLocalDays(from, view === 'week' ? 7 : 1);

    return { from: from.toISOString(), to: to.toISOString() };
}

export function sameInstant(a: string, b: Date): boolean {
    return new Date(a).getTime() === b.getTime();
}

/** Local calendar day of an instant, e.g. "2026-09-29"; the key airings are grouped by. */
export function localDayKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
}

/** The local days in [from, to), each with its airings in time order (the server sorts ascending). */
export function groupByLocalDay(airings: ScheduleAiring[], from: string, to: string): { day: Date; airings: ScheduleAiring[] }[] {
    const days: { day: Date; airings: ScheduleAiring[] }[] = [];
    const end = new Date(to).getTime();

    for (let day = startOfLocalDay(new Date(from)); day.getTime() < end; day = addLocalDays(day, 1)) {
        days.push({ day, airings: [] });
    }

    const byKey = new Map(days.map((entry) => [localDayKey(entry.day), entry]));

    for (const airing of airings) {
        byKey.get(localDayKey(new Date(airing.airsAt)))?.airings.push(airing);
    }

    return days;
}

/* -------------------------------------------------------------------- URL */

/** Every parameter, always, even when empty, like /anime (pagination-safe). */
export function scheduleQueryParams(state: ScheduleState): QueryParams {
    return {
        view: state.view,
        from: state.from,
        to: state.to,
        season: state.season ?? '',
        year: state.year === null ? '' : String(state.year),
        format: state.format,
        linked: state.linked,
        tracked: state.tracked,
        adult: state.adult,
    };
}

const URL_KEYS = ['view', 'from', 'to', 'season', 'year', 'format', 'linked', 'tracked', 'adult'];

/** Which schedule parameters the URL carries at all (`format[]`/`format[0]` count as `format`). */
export function urlParamKeys(url: string): Set<string> {
    const params = new URLSearchParams(url.split('?')[1] ?? '');
    const present = new Set<string>();

    for (const key of params.keys()) {
        const base = key.replace(/\[\d*\]$/, '');

        if (URL_KEYS.includes(base)) {
            present.add(base);
        }
    }

    return present;
}

export function viewFromUrl(url: string): ScheduleView | null {
    const view = new URLSearchParams(url.split('?')[1] ?? '').get('view');

    return view === 'today' || view === 'week' ? view : null;
}

/* ---------------------------------------------------------------- storage */

const STORAGE_KEY = 'torii.schedule.filters';

type Saved = Partial<Omit<ScheduleState, 'from' | 'to'>>;

/**
 * The remembered filters and view. Never the range: a return visit should open
 * on today or this week, not on whichever week was last looked at.
 */
export function loadSavedSchedule(): Saved | null {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);
        const saved = raw ? (JSON.parse(raw) as unknown) : null;

        return saved !== null && typeof saved === 'object' ? (saved as Saved) : null;
    } catch {
        return null;
    }
}

/** Stores only what differs from the defaults; nothing left means the key is removed. */
export function saveSchedule(state: ScheduleState) {
    const saved: Saved = {};

    if (state.view !== 'today') saved.view = state.view;
    if (state.season !== null) saved.season = state.season;
    if (state.year !== null) saved.year = state.year;
    if (state.format.length > 0) saved.format = state.format;
    if (state.linked !== 'all') saved.linked = state.linked;
    if (state.tracked !== 'all') saved.tracked = state.tracked;
    if (state.adult !== 'hide') saved.adult = state.adult;

    try {
        if (Object.keys(saved).length === 0) {
            localStorage.removeItem(STORAGE_KEY);
        } else {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(saved));
        }
    } catch {
        // Private mode or blocked storage: the page still works, it just won't remember.
    }
}

export function clearSavedSchedule() {
    try {
        localStorage.removeItem(STORAGE_KEY);
    } catch {
        // See saveSchedule.
    }
}

/** Saved values, checked field by field, over the defaults. */
export function restoredState(saved: Saved | null): Omit<ScheduleState, 'from' | 'to'> {
    const defaults = { ...DEFAULT_SCHEDULE_FILTERS, view: 'today' as ScheduleView };

    if (!saved) {
        return defaults;
    }

    return {
        view: saved.view === 'week' ? 'week' : 'today',
        season: typeof saved.season === 'string' ? saved.season : null,
        year: typeof saved.year === 'number' ? saved.year : null,
        format: Array.isArray(saved.format) ? saved.format.filter((value) => typeof value === 'string') : [],
        linked: saved.linked === 'linked' || saved.linked === 'unlinked' ? saved.linked : 'all',
        tracked: saved.tracked === 'tracked' ? 'tracked' : 'all',
        adult: saved.adult === 'include' || saved.adult === 'only' ? saved.adult : 'hide',
    };
}

/* ---------------------------------------------------------------- display */

export function scheduleTitle(anime: ScheduleAiring['anime']): string {
    return anime.titleEnglish ?? anime.titleRomaji ?? `Anime #${anime.id}`;
}

export const timeOfDay = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });
export const longDay = new Intl.DateTimeFormat(undefined, { weekday: 'long', day: 'numeric', month: 'long' });
export const shortWeekday = new Intl.DateTimeFormat(undefined, { weekday: 'short' });
export const dayMonth = new Intl.DateTimeFormat(undefined, { day: 'numeric', month: 'short' });

export function isMine(airing: ScheduleAiring): boolean {
    return airing.show?.isTracked === true;
}
