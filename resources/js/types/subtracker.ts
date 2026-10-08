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
    /** Every check, ok or not (HealthChecks); the UI renders only the failing ones. */
    checks: HealthCheck[];
}

export interface HealthCheck {
    /** e.g. `qbit.reachable`, `qbit.auth`, `notifications`, `bootstrap`, `feed.polling`. */
    key: string;
    ok: boolean;
    label: string;
    /** Why it failed (or, when ok, a note such as "Disabled: NTFY_URL is not set."). */
    detail: string | null;
    /**
     * Not run because qBittorrent was unreachable or the login failed ("Not checked: …").
     * The UI folds these into that one failure instead of badging each.
     */
    skipped: boolean;
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
              isTracked: boolean;
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

/* ---------------------------------------------------------------------------
 * Nyaa import (NyaaImportController, NyaaImportPreviews, NyaaImporter)
 * ------------------------------------------------------------------------- */

/**
 * `new` items can still be unselected by default (a remake, or an episode covered
 * by a batch in the list); `reason` then says why.
 */
export type NyaaItemState = 'new' | 'in_qbit' | 'known_release' | 'superseded' | 'unparsed';

export interface NyaaPreviewItem {
    /** Stable within the preview (Nyaa's id); what confirm takes. */
    key: string;
    title: string;
    showName: string | null;
    episode: string | null;
    version: number | null;
    isBatch: boolean;
    batchFrom: number | null;
    batchTo: number | null;
    resolution: string | null;
    infohash: string | null;
    magnet: string | null;
    torrentUrl: string;
    viewUrl: string;
    publishedAt: string | null;
    /** As Nyaa writes it, e.g. "1.4 GiB". */
    size: string | null;
    seeders: number | null;
    trusted: boolean;
    remake: boolean;
    state: NyaaItemState;
    /** The backend's suggested default. */
    selected: boolean;
    /** Why it isn't selected, in plain words; null for a plain new item. */
    reason: string | null;
}

/** POST /import/nyaa/preview — a server-side snapshot, confirmed by `previewId`. */
export interface NyaaPreview {
    previewId: string;
    expiresAt: string;
    feedTitle: string;
    /** The feed came back full (one Nyaa page); there may be more results. */
    truncated: boolean;
    /** Sorted by show (unparsed last), batches first, then episode and version. */
    items: NyaaPreviewItem[];
    /** The one show every parsed item names; null when the feed spans several shows (or none). */
    show: { existingShowId: number | null; name: string; willCreate: boolean } | null;
    /** e.g. qBittorrent unreachable, or several shows in one feed. */
    warnings: string[];
}

/** POST /import/nyaa/confirm */
export interface NyaaConfirmResult {
    queued: number;
    skipped: { key: string; title: string; reason: string }[];
    errors: { key: string; title: string; message: string }[];
    shows: { id: number; name: string; created: boolean }[];
}

/** GET /anime/{id}/card — the hover/tap detail card (AnimeController::card, cached 2 minutes). */
export interface AnimeCardData {
    id: number;
    titleRomaji: string | null;
    titleEnglish: string | null;
    coverUrl: string | null;
    coverWidth: number | null;
    coverHeight: number | null;
    format: string | null;
    status: string | null;
    season: string | null;
    seasonYear: number | null;
    episodesTotal: number | null;
    durationMinutes: number | null;
    /** AniList HTML, cut server-side to a few hundred characters; may leave elements open. Unsanitised. */
    description: string | null;
    descriptionTruncated: boolean;
    genres: string[];
    isAdult: boolean;
    siteUrl: string | null;
    linkedShow: { id: number; name: string; isTracked: boolean } | null;
}

/* ---------------------------------------------------------------------------
 * Schedule (ScheduleController::index)
 * ------------------------------------------------------------------------- */

/** This episode's state in Torii; null for unlinked anime and future airings. */
export type ScheduleReleaseState = 'downloaded' | 'released' | 'waiting';

export interface ScheduleAiring {
    episode: number;
    /** ISO 8601, UTC. */
    airsAt: string;
    isEstimate: boolean;
    anime: {
        id: number;
        titleRomaji: string | null;
        titleEnglish: string | null;
        coverUrl: string | null;
        coverWidth: number | null;
        coverHeight: number | null;
        format: string | null;
        status: string | null;
        episodesTotal: number | null;
        season: string | null;
        seasonYear: number | null;
        isAdult: boolean;
    };
    /** The linked show (a tracked one first when several), via the anime link. */
    show: { id: number; name: string; isTracked: boolean } | null;
    releaseState: ScheduleReleaseState | null;
    /** Episode 1, or the anime started within the last 14 days. */
    isNewSeries: boolean;
}

export interface ScheduleFilters {
    /** ISO 8601; the server applies [from, to). */
    from: string;
    to: string;
    season: AnimeSeason | null;
    year: number | null;
    format: string[];
    linked: 'all' | 'linked' | 'unlinked';
    tracked: 'all' | 'tracked';
    adult: AdultFilter;
}

export interface ScheduleFilterOptions {
    seasons: AnimeSeason[];
    years: number[];
    formats: FacetOption[];
}

export interface ScheduleProps {
    airings: ScheduleAiring[];
    filters: ScheduleFilters;
    filterOptions: ScheduleFilterOptions;
}
