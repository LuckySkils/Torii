import { type ImageStatus, type LatestRelease, type ReleaseSummary, type SuggestionReason } from '@/types/subtracker';

type Titled = { titleEnglish: string | null; titleRomaji: string | null };

/** English title if AniList has one, else romaji. */
export function animeTitle(anime: Titled, fallback = 'Untitled'): string {
    return anime.titleEnglish ?? anime.titleRomaji ?? fallback;
}

/** The romaji title, only when it adds something beyond the English one. */
export function animeSubtitle(anime: Titled): string | null {
    if (!anime.titleEnglish || !anime.titleRomaji) {
        return null;
    }

    return anime.titleRomaji.toLowerCase() === anime.titleEnglish.toLowerCase() ? null : anime.titleRomaji;
}

const FORMAT_LABELS: Record<string, string> = {
    TV: 'TV',
    TV_SHORT: 'TV short',
    MOVIE: 'Movie',
    SPECIAL: 'Special',
    OVA: 'OVA',
    ONA: 'ONA',
    MUSIC: 'Music',
};

export function formatLabel(format: string | null): string | null {
    return format === null ? null : (FORMAT_LABELS[format] ?? humanize(format));
}

const STATUS_LABELS: Record<string, string> = {
    RELEASING: 'Airing',
    NOT_YET_RELEASED: 'Not yet aired',
    FINISHED: 'Finished',
    CANCELLED: 'Cancelled',
    HIATUS: 'On hiatus',
};

export function statusLabel(status: string | null): string | null {
    return status === null ? null : (STATUS_LABELS[status] ?? humanize(status));
}

const SEASON_LABELS: Record<string, string> = {
    WINTER: 'Winter',
    SPRING: 'Spring',
    SUMMER: 'Summer',
    // Shows elsewhere in Torii say "Autumn"; keep one word for the same quarter.
    FALL: 'Autumn',
};

export function animeSeasonLabel(season: string | null, year: number | null): string | null {
    const name = season === null ? null : (SEASON_LABELS[season] ?? humanize(season));

    if (name && year) {
        return `${name} ${year}`;
    }

    return name ?? (year ? String(year) : null);
}

/** "12 / 24" (aired / total), or whichever half is known. */
export function episodeCount(aired: number | null, total: number | null): string | null {
    if (aired !== null && total !== null) {
        return `${aired} / ${total} eps`;
    }

    if (total !== null) {
        return `${total} ${total === 1 ? 'ep' : 'eps'}`;
    }

    return aired !== null ? `${aired} aired` : null;
}

const RULE_LABELS: Record<string, string> = {
    exact: 'exact title',
    subtitle_prefix: 'title before the subtitle',
    season_marker: 'same title and season',
    similarity: 'similar title',
};

export function ruleLabel(rule: string): string {
    return RULE_LABELS[rule] ?? humanize(rule);
}

function humanize(value: string): string {
    const words = value.toLowerCase().replace(/_/g, ' ');

    return words.charAt(0).toUpperCase() + words.slice(1);
}

interface PosterSource {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageWidth?: number | null;
    imageHeight?: number | null;
    anime?: { coverUrl: string | null; coverWidth?: number | null; coverHeight?: number | null } | null;
}

/**
 * AniList covers are consistently portrait, SubsPlease art isn't, so a linked
 * show's cover wins; then the SubsPlease image; then the placeholder.
 */
export function posterFor(show: PosterSource): { imageUrl: string | null; imageStatus: ImageStatus; imageWidth: number | null; imageHeight: number | null } {
    if (show.anime?.coverUrl) {
        return coverPoster(show.anime);
    }

    return { imageUrl: show.imageUrl, imageStatus: show.imageStatus, imageWidth: show.imageWidth ?? null, imageHeight: show.imageHeight ?? null };
}

/** ShowPoster props for an AniList cover, using its real dimensions when known. */
export function coverPoster(anime: { coverUrl: string | null; coverWidth?: number | null; coverHeight?: number | null }): {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageWidth: number | null;
    imageHeight: number | null;
} {
    return {
        imageUrl: anime.coverUrl,
        imageStatus: anime.coverUrl ? 'found' : 'missing',
        imageWidth: anime.coverWidth ?? null,
        imageHeight: anime.coverHeight ?? null,
    };
}

/** The episode a show's latest release represents: the episode number, or a batch's last episode. */
export function latestEpisodeNumber(latest: LatestRelease | null | undefined): number | null {
    if (!latest) {
        return null;
    }

    const number = latest.isBatch ? latest.batchTo : latest.episode === null ? null : Number.parseFloat(latest.episode);

    return number !== null && Number.isFinite(number) ? number : null;
}

/**
 * "Aired but not released": a tracked, linked show whose AniList aired count is
 * ahead of the newest episode Torii has. Null when either number is missing or
 * they match (which also covers finished shows that are complete).
 */
export function airedAhead(isTracked: boolean, episodesAired: number | null | undefined, newestReleased: number | null): { from: number; to: number } | null {
    if (!isTracked || episodesAired == null || newestReleased === null || episodesAired <= newestReleased) {
        return null;
    }

    return { from: Math.floor(newestReleased) + 1, to: episodesAired };
}

export function airedAheadLabel(range: { from: number; to: number }): string {
    return range.from === range.to ? `Ep ${range.to} aired, not released yet` : `Eps ${range.from}–${range.to} aired, not released yet`;
}

/**
 * Why a candidate was only suggested. `episode_count` means the backend distrusts
 * it; say what doesn't fit when both numbers are known.
 */
export function suggestionReasonText(reason: SuggestionReason, episodesTotal: number | null, showEpisode: number | null): string {
    if (reason === 'episode_count') {
        if (episodesTotal !== null && showEpisode !== null) {
            return `Episode count doesn't fit: ${episodesTotal} episode${episodesTotal === 1 ? '' : 's'}, but you're at ${showEpisode}. Probably another season, or SubsPlease numbers on from season 1.`;
        }

        return "Episode count doesn't fit this show. Probably another season.";
    }

    if (reason === 'show_has_rejection') {
        return "You've rejected a match for this show before, so Torii won't link it automatically. Confirm by hand.";
    }

    return 'Another candidate matched almost as well, so Torii left the choice to you.';
}

/** The highest episode number Torii has a release for, batches included. */
export function newestReleasedEpisode(releases: ReleaseSummary[]): number | null {
    let newest: number | null = null;

    for (const release of releases) {
        const number = release.isBatch ? release.batchTo : release.episode === null ? null : Number.parseFloat(release.episode);

        if (number !== null && Number.isFinite(number) && (newest === null || number > newest)) {
            newest = number;
        }
    }

    return newest;
}
