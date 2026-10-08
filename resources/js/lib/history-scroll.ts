import { usePage } from '@inertiajs/react';
import { useLayoutEffect } from 'react';

/**
 * Back/Forward scroll restoration, completed.
 *
 * Inertia saves the window's scroll position into each history entry and
 * restores it on popstate, but in @inertiajs/react 2.0.3 it restores as soon as
 * its swap promise resolves, which is before React has rendered the restored
 * page. The browser clamps the position to the page still on screen (the one
 * being left, often much shorter), so a long list came back at or near the top.
 *
 * This doesn't store anything itself: after React has committed the restored
 * page, it re-applies the position Inertia saved in that history entry.
 */
let historyNavigationPending = false;

/** Call once at boot, next to Inertia's own popstate handling. */
export function trackHistoryNavigation() {
    window.addEventListener('popstate', () => {
        historyNavigationPending = true;
    });
}

interface InertiaHistoryState {
    documentScrollPosition?: { top: number; left: number };
}

/** In the app layout: runs after each page commit, acting only after a Back/Forward. */
export function useRestoreScrollAfterHistoryNavigation() {
    const { url, component } = usePage();

    useLayoutEffect(() => {
        if (!historyNavigationPending) {
            return;
        }

        historyNavigationPending = false;
        const position = (window.history.state as InertiaHistoryState | null)?.documentScrollPosition;

        if (position) {
            window.scrollTo(position.left, position.top);
        }
    }, [url, component]);
}
