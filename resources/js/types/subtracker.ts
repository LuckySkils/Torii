export type RuleState = 'none' | 'pending' | 'synced' | 'disabled' | 'error';

export type DriverMode = 'rules' | 'push';

export type PollMode = 'base' | 'hot';

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

/**
 * Mirrors ReleaseResource. `version` and `isBatch` are not sent by the
 * backend yet (see the frontend report); keep them optional and render
 * the v2/batch badges only when present.
 */
export interface ReleaseSummary {
    id: number;
    title: string;
    show: ReleaseShowRef | null;
    episode: string | null;
    publishedAt: string;
    firstSeenAt: string;
    link: string;
    version?: number | null;
    isBatch?: boolean;
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

export interface ShowListItem {
    id: number;
    name: string;
    latestEpisode: string | null;
    lastSeenAt: string;
    isTracked: boolean;
    ruleState: RuleState;
    ruleError: string | null;
    latestRelease: ShowLatestRelease | null;
}

export interface PaginationLinkItem {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
}

/** Shape produced by ShowResource::collection($paginator) via Inertia. */
export interface PaginatedShows {
    data: ShowListItem[];
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

export interface ShowDetail {
    id: number;
    name: string;
    latestEpisode: string | null;
    lastSeenAt: string;
    isTracked: boolean;
    ruleState: RuleState;
    ruleError: string | null;
}

export interface ShowShowProps {
    show: ShowDetail;
    releases: ReleaseSummary[];
}

/** GET /shows/{show}/matches — qBit's rss/matchingArticles, keyed by feed name. */
export type MatchingArticles = Record<string, string[]>;
