import { type AnimeCardData } from '@/types/subtracker';
import { useEffect, useState } from 'react';

/**
 * GET /anime/{id}/card, shared by every page that shows detail cards (schedule,
 * dashboard, /anime). Results live in memory for the session, so a title hovered
 * once is instant everywhere after. Concurrent callers for the same id share one
 * request, which is aborted only when none of them still wants it.
 */
const cache = new Map<number, AnimeCardData>();
const inflight = new Map<number, { promise: Promise<AnimeCardData>; controller: AbortController; users: number }>();

export class CardLoadError extends Error {
    constructor(public readonly status: number | null) {
        super(status === 404 ? 'This anime no longer exists.' : "Couldn't load the details.");
    }
}

function load(id: number, signal: AbortSignal): Promise<AnimeCardData> {
    let entry = inflight.get(id);

    if (!entry) {
        const controller = new AbortController();
        const promise = fetch(`/anime/${id}/card`, { headers: { Accept: 'application/json' }, signal: controller.signal })
            .then(async (response) => {
                if (!response.ok) {
                    throw new CardLoadError(response.status);
                }

                const data = (await response.json()) as AnimeCardData;
                cache.set(id, data);

                return data;
            })
            .finally(() => {
                if (inflight.get(id)?.promise === promise) {
                    inflight.delete(id);
                }
            });

        entry = { promise, controller, users: 0 };
        inflight.set(id, entry);
    }

    const shared = entry;
    shared.users += 1;
    signal.addEventListener(
        'abort',
        () => {
            shared.users -= 1;

            if (shared.users === 0) {
                shared.controller.abort();
            }
        },
        { once: true },
    );

    return shared.promise;
}

/** Keeps a cached card in step with a change made from it (e.g. tracking its linked show). */
export function patchCachedCard(id: number, patch: (card: AnimeCardData) => AnimeCardData) {
    const card = cache.get(id);

    if (card) {
        cache.set(id, patch(card));
    }
}

type CardState = { status: 'idle' } | { status: 'loading' } | { status: 'ready'; data: AnimeCardData } | { status: 'error'; message: string };

/**
 * The card for `id`, fetched only while `active` (the card is open). Closing it
 * before the response arrives aborts the request and drops the result.
 */
export function useAnimeCard(id: number, active: boolean): CardState & { retry: () => void } {
    const [state, setState] = useState<{ id: number; value: CardState }>({ id, value: { status: 'idle' } });
    const [attempt, setAttempt] = useState(0);
    const cached = cache.get(id);

    useEffect(() => {
        if (!active || cache.has(id)) {
            return;
        }

        const controller = new AbortController();
        setState({ id, value: { status: 'loading' } });

        load(id, controller.signal)
            .then((data) => {
                if (!controller.signal.aborted) {
                    setState({ id, value: { status: 'ready', data } });
                }
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted) {
                    setState({
                        id,
                        value: { status: 'error', message: error instanceof CardLoadError ? error.message : "Couldn't load the details." },
                    });
                }
            });

        return () => controller.abort();
    }, [id, active, attempt]);

    const retry = () => setAttempt((value) => value + 1);

    if (cached) {
        return { status: 'ready', data: cached, retry };
    }

    // A state left over from another id (the component was reused) reads as loading.
    return { ...(state.id === id ? state.value : { status: active ? 'loading' : 'idle' }), retry };
}
