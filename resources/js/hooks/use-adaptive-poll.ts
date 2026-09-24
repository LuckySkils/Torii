import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Like Inertia's `usePoll`, but the interval can change after mount.
 *
 * Inertia 2.0's `usePoll` reads its interval once on the first render, so a page
 * that mounts with nothing pending (slow interval) never speeds up when
 * something becomes pending — which left rule badges stuck on "syncing".
 * This restarts the poll whenever the interval changes.
 *
 * `router.reload` always preserves state and scroll, so polling never resets
 * search input, filters, selection or scroll position.
 */
export function useAdaptivePoll(interval: number, only: string[]) {
    const onlyKey = only.join(',');

    useEffect(() => {
        const poll = router.poll(interval, { only: onlyKey.split(',') });

        return () => poll.stop();
    }, [interval, onlyKey]);
}
