import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const ACTIVE_WINDOW_MS = 15000;

/**
 * True for 15s after any mutating (non-GET) Inertia visit starts — track/untrack,
 * bulk track, download, queue-missing, delete-rule, image refresh, etc. Pages use
 * this to poll fast right after an action fires, without prop-drilling a callback
 * through every button down in the table.
 */
export function useRecentActivity(): boolean {
    const [active, setActive] = useState(false);
    const timeoutRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

    useEffect(() => {
        const unsubscribe = router.on('start', (event) => {
            if (event.detail.visit.method === 'get') {
                return;
            }

            setActive(true);
            clearTimeout(timeoutRef.current);
            timeoutRef.current = setTimeout(() => setActive(false), ACTIVE_WINDOW_MS);
        });

        return () => {
            unsubscribe();
            clearTimeout(timeoutRef.current);
        };
    }, []);

    return active;
}
