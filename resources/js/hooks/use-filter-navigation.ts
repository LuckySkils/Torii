import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

const SEARCH_DEBOUNCE_MS = 400;

export type QueryParams = Record<string, string | string[]>;

/**
 * Serializes like Inertia's own visits (`key[]=a&key[]=b`), and keeps empty
 * values: `season=` means "any season" to the backend, while a missing
 * `season` means "the current one".
 */
export function toQueryString(params: QueryParams): string {
    const search = new URLSearchParams();

    for (const [key, value] of Object.entries(params)) {
        if (Array.isArray(value)) {
            value.forEach((item) => search.append(`${key}[]`, item));
        } else {
            search.append(key, value);
        }
    }

    return search.toString();
}

/**
 * Filter-bar navigation for an Inertia index page with a `q` search box:
 * typing is debounced, and every new request cancels the one in flight, so a
 * slow earlier response can never land on top of a newer one.
 *
 * Changes build on the filters of the request in flight, not on the last
 * server response, so ticking two formats (or adding two genres) in quick
 * succession keeps both. `filters` in the result is that pending state, for
 * rendering the controls as the user left them.
 *
 * Pagination goes through here too (`pageHref`/`goToPage`) rather than the
 * paginator's own links: Laravel turns empty params into nulls and drops them
 * from those links, so page 2 of "any season" would silently become page 2 of
 * the current season. Filter changes never carry a page, so they start on page 1.
 */
export function useFilterNavigation<F extends { q: string }>(path: string, serverFilters: F, toQueryParams: (filters: F) => QueryParams) {
    const [search, setSearch] = useState(serverFilters.q);
    const [searching, setSearching] = useState(false);
    const [pending, setPending] = useState<F | null>(null);
    const filters = pending ?? serverFilters;

    // Mirrors `filters` for the debounce timer, which would otherwise see the render it was created in.
    const filtersRef = useRef(filters);
    filtersRef.current = filters;

    // Tracks the q value WE last sent to the server. If serverFilters.q changes to
    // something else, it came from outside (browser back/forward) and the input
    // should resync; if it changes to match this, it's just our own request
    // landing and must not clobber whatever the user has typed since.
    const lastSubmitted = useRef(serverFilters.q);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const cancelTokenRef = useRef<{ cancel: VoidFunction } | null>(null);

    useEffect(() => {
        if (serverFilters.q !== lastSubmitted.current) {
            lastSubmitted.current = serverFilters.q;
            setSearch(serverFilters.q);
        }
    }, [serverFilters.q]);

    useEffect(() => {
        return () => clearTimeout(debounceRef.current);
    }, []);

    /**
     * Filter changes replace the history entry and show the search spinner if asked;
     * page changes push one, so Back returns to the previous page.
     */
    function navigate(next: F, { spinner = false, page = 1, push = false }: { spinner?: boolean; page?: number; push?: boolean } = {}) {
        clearTimeout(debounceRef.current);
        cancelTokenRef.current?.cancel();
        lastSubmitted.current = next.q;
        setSearch(next.q);
        setPending(next);

        router.get(
            hrefFor(next, page),
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: !push,
                onCancelToken: (token) => {
                    cancelTokenRef.current = token;
                },
                onStart: () => spinner && setSearching(true),
                onFinish: (visit) => {
                    if (spinner) {
                        setSearching(false);
                    }

                    // A cancelled or interrupted visit was superseded; its successor owns `pending`.
                    if (!visit.cancelled && !visit.interrupted) {
                        setPending(null);
                    }
                },
            },
        );
    }

    /** Apply filter changes right away, keeping whatever is typed in the search box. */
    function submit(overrides: Partial<F>) {
        navigate({ ...filtersRef.current, q: search, ...overrides });
    }

    function changeSearch(value: string) {
        setSearch(value);
        clearTimeout(debounceRef.current);

        debounceRef.current = setTimeout(() => {
            if (value !== filtersRef.current.q) {
                navigate({ ...filtersRef.current, q: value }, { spinner: true });
            }
        }, SEARCH_DEBOUNCE_MS);
    }

    function hrefFor(state: F, page: number): string {
        const params = page > 1 ? { ...toQueryParams(state), page: String(page) } : toQueryParams(state);

        return `${path}?${toQueryString(params)}`;
    }

    /** A pagination link for the current filters: the full state, empty params included. */
    function pageHref(page: number): string {
        return hrefFor(filtersRef.current, page);
    }

    function goToPage(page: number) {
        navigate(filtersRef.current, { page, push: true });
    }

    return { filters, search, searching, changeSearch, submit, navigate, pageHref, goToPage };
}

export type FilterNavigation<F extends { q: string }> = ReturnType<typeof useFilterNavigation<F>>;
