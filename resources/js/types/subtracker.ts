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

/**
 * Where a downloaded episode is on its way to being playable in Jellyfin (§17,
 * App\Enums\DeliveryState). `awaiting_link`: Shoko hasn't linked the file to its
 * series yet, so the library refresh waits for it (a waiting state, not a failure).
 */
export type DeliveryState =
    | 'downloaded'
    | 'matched'
    | 'awaiting_series'
    | 'in_jellyfin'
    | 'awaiting_link'
    | 'fixing_a'
    | 'fixing_b'
    | 'playable'
    | 'gave_up';

/** Which reconciler fixes ran (App\Enums\FixAttempted). */
export type DeliveryFixAttempted = 'none' | 'a' | 'b' | 'a_then_b';

/** Delivery::toProps(): a release's trail towards Jellyfin, when the reconciler tracks it (§17). */
export interface Delivery {
    state: DeliveryState;
    isNewShow: boolean;
    fixAttempted: DeliveryFixAttempted;
    /** Dry run: the fixes it would have sent, as text ("fix A (refresh the Anime library), then fix B (…)"). */
    wouldHaveFixed: string | null;
    lastError: string | null;
    stages: {
        matchedAt: string | null;
        inJellyfinAt: string | null;
        playableAt: string | null;
        gaveUpAt: string | null;
    };
}

export interface ReleaseShowRef {
    id: number;
    name: string;
}

/** Mirrors ReleaseResource exactly (CLAUDE.md §8). */
export interface ReleaseSummary {
    id: number;
    title: string;
    /** The release group: "SubsPlease" for the feed, the parsed [Group] for a Nyaa import. */
    group: string | null;
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
    /** Null when the reconciler doesn't track it (off, a batch, not downloaded yet). */
    delivery: Delivery | null;
}

/** LatestReleaseResource — the Release shape, with poster fields on `show` and two novelty flags. */
export interface DashboardRelease extends Omit<ReleaseSummary, 'show'> {
    show:
        | (ReleaseShowRef & {
              imageUrl: string | null;
              imageStatus: ImageStatus;
              isTracked: boolean;
              trackable: boolean;
              trackBlockedReason: string | null;
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
    /** False when the show has no SubsPlease release: tracking it is refused (§16). Tracked shows are always trackable. */
    trackable: boolean;
    /** Why it can't be tracked; null whenever `trackable` is true. */
    trackBlockedReason: string | null;
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
    trackable: boolean;
    trackBlockedReason: string | null;
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
 * Precedence order (§16). `new` items can still be unselected by default (a remake,
 * or an episode covered by a batch of the same series); `reason` then says why.
 */
export type NyaaItemState = 'unparsed' | 'in_qbit' | 'known_release' | 'have_episode' | 'superseded' | 'duplicate_episode' | 'new';

export interface NyaaPreviewItem {
    /** Stable within the preview (Nyaa's id); what confirm takes. */
    key: string;
    title: string;
    /** The parsed release group ("SubsPlease", "Erai-raws", …). */
    group: string | null;
    /** The parsed suggested show name ("Black Clover S2"); null when unparsed. */
    showName: string | null;
    /** The parsed name without the season suffix ("Black Clover"). */
    parsedName: string | null;
    /** From an explicit SxxEyy/Sxx marker only; SubsPlease titles keep the season in the name (null here). */
    season: number | null;
    /** Null when unparsed. */
    seriesKey: string | null;
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

/**
 * An anime from Torii's catalog, as the Nyaa import shows it. `title` is the
 * English title, else the romaji one, else "#id". `coverUrl` is Torii's own
 * cover URL, null until the cover is downloaded.
 */
export interface NyaaAnimeRef {
    id: number;
    title: string;
    titleRomaji: string | null;
    titleEnglish: string | null;
    season: string | null;
    seasonYear: number | null;
    format: string | null;
    coverUrl: string | null;
}

/**
 * Where a series' items go: an existing show, or a show created on confirm.
 * `anime` is the anime confirming links the show to: on `new`, the new show's
 * anime (null = no link); on `existing`, only for an unlinked show picked
 * through an anime.
 */
export type NyaaTarget =
    | { kind: 'existing'; showId: number; showName: string; trackable: boolean; trackBlockedReason: string | null; anime: NyaaAnimeRef | null }
    | { kind: 'new'; name: string; trackable: boolean; trackBlockedReason: string | null; anime: NyaaAnimeRef | null };

/**
 * Where the series may belong; one list, best first, at most 5 (ties: shows first).
 * An anime candidate is an anime with no show yet: picking it means a new show linked to it.
 */
export type NyaaCandidate =
    | { kind: 'show'; showId: number; showName: string; why: string; score: number; trackable: boolean }
    | { kind: 'anime'; anime: NyaaAnimeRef; why: string; score: number; trackable: boolean };

/** Items the backend groups as one show (e.g. Black Clover S2 from Judas and Erai-raws together). */
export interface NyaaSeries {
    /** e.g. "blackclover|s2". */
    key: string;
    parsedName: string;
    season: number | null;
    suggestedName: string;
    altTitles: string[];
    /** SubsPlease first, then alphabetical. */
    groups: string[];
    itemKeys: string[];
    target: NyaaTarget;
    candidates: NyaaCandidate[];
}

/** POST /import/nyaa/preview and POST /import/nyaa/preview/{previewId}/target — a server-side snapshot, confirmed by `previewId`. */
export interface NyaaPreview {
    previewId: string;
    expiresAt: string;
    feedTitle: string;
    /** The feed came back full (one Nyaa page); there may be more results. */
    truncated: boolean;
    /** Series order, batches first, episode order, SubsPlease first, unparsed last. */
    items: NyaaPreviewItem[];
    /** By suggested name. */
    series: NyaaSeries[];
    /** e.g. qBittorrent unreachable. */
    warnings: string[];
}

/** An anime in the target picker's search, with the shows already linked to it. */
export type NyaaAnimeHit = NyaaAnimeRef & { linkedShows: { id: number; name: string }[] };

/**
 * GET /import/nyaa/shows?q=&provider=1 — the target picker's search: shows by
 * name (at most 20) and anime (at most 10, 20 with the online search).
 */
export interface NyaaShowSearch {
    shows: { id: number; name: string; isTracked: boolean; trackable: boolean; latestEpisode: string | null }[];
    anime: NyaaAnimeHit[];
    /** True only with provider=1, a non-empty q and within the limit. */
    providerSearched: boolean;
    /** A neutral text when the online search was busy or failed; the local rows are still there. */
    providerError: string | null;
}

/** POST /import/nyaa/confirm */
export interface NyaaConfirmResult {
    queued: number;
    skipped: { key: string; title: string; reason: string }[];
    errors: { key: string; title: string; message: string }[];
    /** `linkedAnime`: the show's anime link after the import, whatever its source. */
    shows: { id: number; name: string; created: boolean; linkedAnime: { id: number; title: string } | null }[];
    /** Linking notes, e.g. an anime that got another show meanwhile. */
    warnings: string[];
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
    linkedShow: { id: number; name: string; isTracked: boolean; trackable: boolean; trackBlockedReason: string | null } | null;
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
    show: { id: number; name: string; isTracked: boolean; trackable: boolean; trackBlockedReason: string | null } | null;
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
