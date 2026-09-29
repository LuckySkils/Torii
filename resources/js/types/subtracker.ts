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
    /** One-time data tasks; null once all have completed. */
    bootstrap: BootstrapProgress | null;
}

export type BootstrapTaskState = 'pending' | 'running' | 'failed' | 'done';

export interface BootstrapProgress {
    total: number;
    completed: number;
    tasks: { key: string; label: string; state: BootstrapTaskState; error: string | null }[];
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
    show:
        | (ReleaseShowRef & {
              imageUrl: string | null;
              imageStatus: ImageStatus;
              anime: AnimeLinkSummary | null;
              hasSuggestions: boolean;
          })
        | null;
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
    /** Automatic matching found candidates too close to call; they're waiting for review. */
    hasSuggestions: boolean;
    /** Null when unlinked. The summary on lists; Shows/Show gets AnimeLinkFull. */
    anime: AnimeLinkSummary | null;
}

export interface PaginationLinkItem {
    url: string | null;
    label: string;
    page: number | null;
    active: boolean;
}

/** Laravel's paginated-resource shape: `{ data, links, meta }`. */
export interface Paginated<T> {
    data: T[];
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

export type PaginatedShows = Paginated<ShowSummary>;

export interface ShowFilters {
    q: string;
    tracked: 'all' | 'yes' | 'no';
    sort: 'name' | 'last_seen' | 'premiered';
    season: Season | null;
    year: number | null;
    /** review=1: only shows with link suggestions waiting; combines with the rest. */
    review: boolean;
    /** format[]: linked anime has any of these (AniList formats, uppercase). */
    format: string[];
    /** genres_include[]: linked anime has all of these. */
    genresInclude: string[];
    /** genres_exclude[]: linked anime has none of these (unlinked shows stay). */
    genresExclude: string[];
}

/** AnimeFacets row: a filter value with how many items have it (over everything, not the current result). */
export interface FacetOption {
    value: string;
    count: number;
}

export interface FilterOptions {
    years: number[];
    /** Shows with pending suggestions, whatever the other filters. */
    reviewCount: number;
    /** Counted over shows' linked anime. */
    formats: FacetOption[];
    genres: FacetOption[];
}

export interface ShowsIndexProps {
    shows: PaginatedShows;
    filters: ShowFilters;
    filterOptions: FilterOptions;
}

export interface ShowShowProps {
    show: ShowSummary & { anime: AnimeLinkFull | null };
    releases: ReleaseSummary[];
}

/** GET /shows/{show}/matches — qBit's rss/matchingArticles, keyed by feed name. */
export type MatchingArticles = Record<string, string[]>;

// ---- Anime metadata (phase 9) -------------------------------------------------

export type LinkSource = 'auto' | 'manual';

/** AniList's own vocabularies, passed through as-is. */
export type AnimeSeason = 'WINTER' | 'SPRING' | 'SUMMER' | 'FALL';

export type MatchRule = 'exact' | 'subtitle_prefix' | 'season_marker' | 'similarity';

/** LinkedAnimeResource summary — `anime` on show lists and dashboard entries. */
export interface AnimeLinkSummary {
    id: number;
    titleRomaji: string | null;
    titleEnglish: string | null;
    /** Torii-served cover, null until downloaded. */
    coverUrl: string | null;
    coverWidth: number | null;
    coverHeight: number | null;
    episodesAired: number | null;
    linkSource: LinkSource;
}

/** LinkedAnimeResource full — `show.anime` on Shows/Show. `description` is AniList HTML, unsanitised. */
export interface AnimeLinkFull extends AnimeLinkSummary {
    genres: string[];
    episodesTotal: number | null;
    status: string | null;
    format: string | null;
    durationMinutes: number | null;
    description: string | null;
    season: string | null;
    seasonYear: number | null;
    nextAiringAt: string | null;
    nextEpisode: number | null;
    siteUrl: string | null;
    airingWindow: AiringWindow;
    confidence: number;
}

/** A row of GET /shows/{show}/link/search. */
export interface LinkSearchResult {
    id: number;
    titleRomaji: string | null;
    titleEnglish: string | null;
    titleNative: string | null;
    season: string | null;
    seasonYear: number | null;
    format: string | null;
    episodesTotal: number | null;
    coverUrl: string | null;
    coverWidth: number | null;
    coverHeight: number | null;
    /** The season number the titles imply (1 when none says otherwise). */
    seasonNumber: number;
    score: number;
    linkedShows: ReleaseShowRef[];
}

export interface LinkSearchResponse {
    /** The show name, or the typed text. */
    query: string;
    /** What was sent to the provider: without a typed q, the show name minus its season marker. */
    searchedQuery: string;
    /** The season number wanted; only ranks results. */
    season: number;
    results: LinkSearchResult[];
    providerSearched: boolean;
    providerError: string | null;
}

/** A row of GET /shows/{show}/link/suggestions. */
/** Why matching suggested a candidate instead of linking it (SuggestionReason enum). */
export type SuggestionReason = 'ambiguous' | 'episode_count' | 'show_has_rejection';

export interface LinkSuggestion extends LinkSearchResult {
    rule: MatchRule;
    reason: SuggestionReason;
    createdAt: string;
}

export interface AnimeLinkedShowRef {
    id: number;
    name: string;
    linkSource: LinkSource;
    confidence: number;
}

/** AnimeResource — one anime on Anime/Index. */
export interface AnimeItem {
    id: number;
    titleRomaji: string | null;
    titleEnglish: string | null;
    titleNative: string | null;
    coverUrl: string | null;
    coverWidth: number | null;
    coverHeight: number | null;
    format: string | null;
    status: string | null;
    season: string | null;
    seasonYear: number | null;
    episodesTotal: number | null;
    episodesAired: number | null;
    genres: string[];
    startDate: string | null;
    nextAiringAt: string | null;
    nextEpisode: number | null;
    isAdult: boolean;
    linkedShows: AnimeLinkedShowRef[];
}

/** AnimeResource::detail() — Anime/Show. `description` is AniList HTML, unsanitised. */
export interface AnimeDetail extends AnimeItem {
    synonyms: string[];
    description: string | null;
    endDate: string | null;
    durationMinutes: number | null;
    siteUrl: string | null;
    externalIds: Record<string, string>;
    primaryProvider: string;
    syncedAt: string;
    airingWindow: AiringWindow;
}

export interface AiringWindowEntry {
    episode: number;
    airsAt: string;
}

/**
 * AiringWindow — the three airings around now. `current` is the earliest at or
 * after now (due next, or airing right now), `previous` the latest before now,
 * `next` the one after current. Any may be null.
 */
export interface AiringWindow {
    previous: AiringWindowEntry | null;
    current: AiringWindowEntry | null;
    next: AiringWindowEntry | null;
}

export interface AnimeAiring {
    episode: number;
    airsAt: string;
    isEstimate: boolean;
    provider: string;
}

export interface AnimeShowLinkedShow {
    id: number;
    name: string;
    imageUrl: string | null;
    isTracked: boolean;
    linkSource: LinkSource;
    confidence: number;
    linkedAt: string;
}

export interface AnimeFilters {
    q: string;
    season: AnimeSeason | null;
    year: number | null;
    status: string | null;
    linked: 'all' | 'yes' | 'no';
    /** format[]: any of these. */
    format: string[];
    /** genres_include[]: all of these (the old `genre=` lands here too). */
    genresInclude: string[];
    /** genres_exclude[]: none of these. */
    genresExclude: string[];
    /** AniList isAdult entries: hidden unless asked for. */
    adult: AdultFilter;
}

export type AdultFilter = 'hide' | 'include' | 'only';

export interface AnimeFilterOptions {
    seasons: AnimeSeason[];
    years: number[];
    formats: FacetOption[];
    genres: FacetOption[];
    statuses: string[];
}

export interface AnimeIndexProps {
    anime: Paginated<AnimeItem>;
    filters: AnimeFilters;
    filterOptions: AnimeFilterOptions;
}

export interface AnimeShowProps {
    anime: AnimeDetail;
    /** Newest first. */
    airings: AnimeAiring[];
    linkedShows: AnimeShowLinkedShow[];
}
