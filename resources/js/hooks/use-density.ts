import { useSyncExternalStore } from 'react';

/**
 * Layout density. `compact` is the original sizing (tuned for phones and
 * laptops); `large` gives big screens bigger posters, wider cards and larger
 * text. It's a `data-density` attribute on <html>, styled with the `large:`
 * variant (app.css), and only takes effect from `md` up.
 */
export type Density = 'compact' | 'large';

const STORAGE_KEY = 'torii.density';
const listeners = new Set<() => void>();

function stored(): Density {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'large' ? 'large' : 'compact';
    } catch {
        return 'compact';
    }
}

function apply(density: Density) {
    document.documentElement.dataset.density = density;
}

/** Called once at boot, before the first render, so the page never paints in the wrong density. */
export function initializeDensity() {
    apply(stored());
}

function current(): Density {
    return document.documentElement.dataset.density === 'large' ? 'large' : 'compact';
}

function subscribe(listener: () => void) {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

export function setDensity(density: Density) {
    try {
        localStorage.setItem(STORAGE_KEY, density);
    } catch {
        // Private mode or blocked storage: still switch for this page view.
    }

    apply(density);
    listeners.forEach((listener) => listener());
}

export function useDensity(): [Density, (density: Density) => void] {
    const density = useSyncExternalStore(subscribe, current, () => 'compact' as Density);

    return [density, setDensity];
}
