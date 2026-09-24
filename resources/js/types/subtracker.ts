export type RuleState = 'none' | 'pending' | 'synced' | 'disabled' | 'error';

export type DriverMode = 'rules' | 'push';

export type PollMode = 'base' | 'hot';

export type TrackingMode = 'rule' | 'batch';

export type DispatchStatus = 'sent' | 'exists' | 'error';

export type ImageStatus = 'none' | 'pending' | 'found' | 'missing' | 'error';

export type Season = 'winter' | 'spring' | 'summer' | 'autumn';

export type PremiereSource = 'subsplease' | 'episode1' | 'earliest_seen';

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

/** PollingDelay::recent() — median first_seen_at − published_at; can be negative if the pubDate offset is wrong. */
export interface FeedDelay {
    medianSeconds: number | null;
    sampleSize: number;
}

export interface Health {
    qbit: QbitHealth;
    lastPoll: LastPoll | null;
    nextPollAt: string | null;
    pollMode: PollMode;
    driver: DriverMode;
    delay: FeedDelay;
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
    downloadedAt: string | null;
}

/** LatestReleaseResource — the Release shape, with poster fields on `show` and two novelty flags. */
export interface DashboardRelease extends Omit<ReleaseSummary, 'show'> {
    show: (ReleaseShowRef & { imageUrl: string | null; imageStatus: ImageStatus }) | null;
    isFirstEpisode: boolean;
    isNewShow: boolean;
}

export interface DashboardProps {
    health: Health;
    latestReleases: DashboardRelease[];
}

export interface LatestRelease {
    episode: string | null;
    isBatch: boolean;
    batchFrom: number | null;
    batchTo: number | null;
    publishedAt: string;
}

/** Mirrors ShowResource exactly (agents/CLAUDE.md §9) — one row of `shows`, or the `show` prop on Shows/Show. */
export interface ShowSummary {
    id: number;
    name: string;
    firstSeenAt: string;
    lastSeenAt: string;
    isTracked: boolean;
    trackingMode: TrackingMode | null;
    ruleState: RuleState;
    ruleError: string | null;
    /**
     * Built from `whenLoaded('latestRelease', ...)`: present (possibly null) on
     * Shows/Index (eager-loaded there), entirely absent — `undefined` — on
     * Shows/Show, which doesn't eager-load the relation. That page derives the
     * latest release from `releases[0]` (already sorted newest-first) instead.
     */
    latest?: LatestRelease | null;
    hasBatch: boolean;
    queuedCount: number;
    downloadableCount: number;
    downloadedCount: number;
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageCheckedAt: string | null;
    imageError: string | null;
    imageWidth: number | null;
    imageHeight: number | null;
    season: Season | null;
    seasonYear: number | null;
    premieredAt: string | null;
    premiereSource: PremiereSource | null;
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
    sort: 'name' | 'last_seen' | 'premiered';
    season: Season | null;
    year: number | null;
}

export interface FilterOptions {
    years: number[];
}

export interface ShowsIndexProps {
    shows: PaginatedShows;
    filters: ShowFilters;
    filterOptions: FilterOptions;
}

export interface ShowShowProps {
    show: ShowSummary;
    releases: ReleaseSummary[];
}

/** GET /shows/{show}/matches — qBit's rss/matchingArticles, keyed by feed name. */
export type MatchingArticles = Record<string, string[]>;
