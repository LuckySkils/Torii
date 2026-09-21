import { useEffect, useState } from 'react';

export type ShowsView = 'grid' | 'list';

const STORAGE_KEY = 'torii.showsView';

function readStored(): ShowsView {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);

        return stored === 'list' ? 'list' : 'grid';
    } catch {
        return 'grid';
    }
}

export function useShowsView(): [ShowsView, (view: ShowsView) => void] {
    const [view, setView] = useState<ShowsView>('grid');

    useEffect(() => {
        setView(readStored());
    }, []);

    function update(next: ShowsView) {
        setView(next);

        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // Per-viewer convenience only; fine if it doesn't persist.
        }
    }

    return [view, update];
}
