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

/** Polling delay: first seen minus published. */
export function formatDelay(publishedAt: string, firstSeenAt: string): string {
    return formatDuration(new Date(firstSeenAt).getTime() - new Date(publishedAt).getTime());
}
