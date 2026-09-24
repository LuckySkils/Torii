const RELATIVE_UNITS: Array<{ unit: Intl.RelativeTimeFormatUnit; ms: number }> = [
    { unit: 'year', ms: 365 * 24 * 60 * 60 * 1000 },
    { unit: 'month', ms: 30 * 24 * 60 * 60 * 1000 },
    { unit: 'day', ms: 24 * 60 * 60 * 1000 },
    { unit: 'hour', ms: 60 * 60 * 1000 },
    { unit: 'minute', ms: 60 * 1000 },
    { unit: 'second', ms: 1000 },
];

const relativeFormatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
const absoluteFormatter = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'medium' });

/** e.g. "12 minutes ago" / "in 3 minutes". */
export function formatRelative(iso: string, now: Date = new Date()): string {
    const diffMs = new Date(iso).getTime() - now.getTime();
    const absMs = Math.abs(diffMs);

    for (const { unit, ms } of RELATIVE_UNITS) {
        if (absMs >= ms) {
            return relativeFormatter.format(Math.round(diffMs / ms), unit);
        }
    }

    return relativeFormatter.format(0, 'second');
}

/** Full local date/time for tooltips. */
export function formatAbsolute(iso: string): string {
    return absoluteFormatter.format(new Date(iso));
}

/** e.g. "3 min", "1h 12m", "2d 4h". */
export function formatDuration(ms: number): string {
    const clamped = Math.max(0, ms);
    const totalSeconds = Math.round(clamped / 1000);

    if (totalSeconds < 60) {
        return `${totalSeconds}s`;
    }

    const totalMinutes = Math.round(clamped / 60000);

    if (totalMinutes < 60) {
        return `${totalMinutes} min`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours < 24) {
        return minutes > 0 ? `${hours}h ${minutes}m` : `${hours}h`;
    }

    const days = Math.floor(hours / 24);
    const remainingHours = hours % 24;

    return remainingHours > 0 ? `${days}d ${remainingHours}h` : `${days}d`;
}

const MAX_PLAUSIBLE_DELAY_MS = 7 * 24 * 60 * 60 * 1000;

/**
 * Polling delay: first seen minus published. "—" when negative or over 7 days,
 * which only happens with bad timestamps (e.g. the first poll's backlog or a
 * wrong pubDate offset), never as a real delay.
 */
export function formatDelay(publishedAt: string, firstSeenAt: string): string {
    const ms = new Date(firstSeenAt).getTime() - new Date(publishedAt).getTime();

    if (!Number.isFinite(ms) || ms < 0 || ms > MAX_PLAUSIBLE_DELAY_MS) {
        return '—';
    }

    return formatDuration(ms);
}

/** Compact lag for the health strip, e.g. "45s", "4m", "1h 5m". Keeps the sign. */
export function formatLag(seconds: number): string {
    const sign = seconds < 0 ? '−' : '';
    const abs = Math.abs(Math.round(seconds));

    if (abs < 60) {
        return `${sign}${abs}s`;
    }

    const minutes = Math.round(abs / 60);

    if (minutes < 60) {
        return `${sign}${minutes}m`;
    }

    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return `${sign}${hours}h${rest > 0 ? ` ${rest}m` : ''}`;
}
