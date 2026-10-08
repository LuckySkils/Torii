/**
 * What turning tracking on would queue, for a show whose page props don't carry
 * it (the anime page and the detail card only know `isTracked`). Asks the show
 * page for its `show` prop alone, with Inertia's own partial-reload headers, so
 * the confirm dialog shows the same fresh numbers as on the show page.
 */
export interface ShowQueueInfo {
    downloadableCount: number;
    hasBatch: boolean;
}

export class QueueInfoError extends Error {}

export async function fetchShowQueueInfo(showId: number, inertiaVersion: string | null): Promise<ShowQueueInfo> {
    const response = await fetch(`/shows/${showId}`, {
        headers: {
            Accept: 'text/html, application/xhtml+xml',
            'X-Requested-With': 'XMLHttpRequest',
            'X-Inertia': 'true',
            ...(inertiaVersion ? { 'X-Inertia-Version': inertiaVersion } : {}),
            'X-Inertia-Partial-Component': 'Shows/Show',
            'X-Inertia-Partial-Data': 'show',
        },
        credentials: 'same-origin',
    });

    if (response.status === 409) {
        throw new QueueInfoError('Torii was updated since this page loaded. Reload the page and try again.');
    }

    if (!response.ok) {
        throw new QueueInfoError(`Couldn't check what tracking would queue (HTTP ${response.status}).`);
    }

    const page = (await response.json()) as { props?: { show?: Partial<ShowQueueInfo> } };
    const show = page.props?.show;

    if (typeof show?.downloadableCount !== 'number') {
        throw new QueueInfoError("Couldn't check what tracking would queue.");
    }

    return { downloadableCount: show.downloadableCount, hasBatch: show.hasBatch === true };
}
