export type RuleState = 'none' | 'pending' | 'synced' | 'disabled' | 'error';

export type DriverMode = 'rules' | 'push';

export type PollMode = 'base' | 'hot';

export type TrackingMode = 'rule' | 'batch';

export type DispatchStatus = 'sent' | 'exists' | 'error';

export type ImageStatus = 'none' | 'pending' | 'found' | 'missing' | 'error';

export interface QbitHealth {
    reachable: boolean;
    version: string | null;
    webapi: string | null;
    auth: boolean;
    feed: boolean;
    prefs: boolean;
    category: boolean;
}

export interface LastPoll {
    at: string;
    status: number | null;
    notModified: boolean;
    itemsNew: number;
    error: string | null;
}

export interface Health {
    qbit: QbitHealth;
    lastPoll: LastPoll | null;
    nextPollAt: string | null;
    pollMode: PollMode;
    driver: DriverMode;
}

export interface ReleaseShowRef {
    id: number;
    name: string;
}

/** Mirrors ReleaseResource exactly (CLAUDE.md §8). */
export interface ReleaseSummary {
    id: number;
    title: string;
    show: ReleaseShowRef | null;
    episode: string | null;
    version: number | null;
    isBatch: boolean;
    batchFrom: number | null;
    batchTo: number | null;
    publishedAt: string;
    firstSeenAt: string;
    link: string;
    dispatchStatus: DispatchStatus | null;
    dispatchedAt: string | null;
    dispatchError: string | null;
}

export interface DashboardProps {
    health: Health;
    latestReleases: ReleaseSummary[];
}

export interface ShowLatestRelease {
    title: string;
    publishedAt: string;
    link: string;
}

/** Mirrors ShowResource exactly (CLAUDE.md §8) — one row of `shows`, or the `show` prop on Shows/Show. */
export interface ShowSummary {
    id: number;
    name: string;
    latestEpisode: string | null;
    firstSeenAt: string;
    lastSeenAt: string;
    isTracked: boolean;
    trackingMode: TrackingMode | null;
    ruleState: RuleState;
    ruleError: string | null;
    /** Only present when the controller eager-loaded the relation (true on Shows/Index, absent on Shows/Show). */
    latestRelease?: ShowLatestRelease | null;
    hasBatch: boolean;
    queuedCount: number;
    downloadableCount: number;
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageCheckedAt: string | null;
    imageError: string | null;
}

export interface PaginationLinkItem {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
}

/** Shape produced by ShowResource::collection($paginator) via Inertia. */
export interface PaginatedShows {
    data: ShowSummary[];
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
    meta: {
        current_page: number;
        from: number | null;
        last_page: number;
        links: PaginationLinkItem[];
        path: string;
        per_page: number;
        to: number | null;
        total: number;
    };
}

export interface ShowFilters {
    q: string;
    tracked: 'all' | 'yes' | 'no';
    sort: 'name' | 'last_seen';
}

export interface ShowsIndexProps {
    shows: PaginatedShows;
    filters: ShowFilters;
}

export interface ShowShowProps {
    show: ShowSummary;
    releases: ReleaseSummary[];
}

/** GET /shows/{show}/matches — qBit's rss/matchingArticles, keyed by feed name. */
export type MatchingArticles = Record<string, string[]>;
