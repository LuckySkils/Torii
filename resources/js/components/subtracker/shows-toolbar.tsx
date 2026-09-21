import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type ShowFilters } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface ShowsToolbarProps {
    filters: ShowFilters;
}

const SEARCH_DEBOUNCE_MS = 400;

export function ShowsToolbar({ filters }: ShowsToolbarProps) {
    const [search, setSearch] = useState(filters.q);
    const [searching, setSearching] = useState(false);

    // Tracks the q value WE last sent to the server. If filters.q changes to
    // something else, it came from outside this component (clear-filters,
    // browser back/forward) and the input should resync; if it changes to
    // match this, it's just our own request landing and must not clobber
    // whatever the user has typed since.
    const lastSubmitted = useRef(filters.q);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const cancelTokenRef = useRef<{ cancel: VoidFunction } | null>(null);

    useEffect(() => {
        if (filters.q !== lastSubmitted.current) {
            lastSubmitted.current = filters.q;
            setSearch(filters.q);
        }
    }, [filters.q]);

    useEffect(() => {
        return () => clearTimeout(debounceRef.current);
    }, []);

    function navigate(next: ShowFilters, spinner: boolean) {
        cancelTokenRef.current?.cancel();
        lastSubmitted.current = next.q;

        router.get('/shows', next as unknown as Record<string, string>, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onCancelToken: (token) => {
                cancelTokenRef.current = token;
            },
            onStart: () => spinner && setSearching(true),
            onFinish: () => spinner && setSearching(false),
        });
    }

    function handleSearchChange(value: string) {
        setSearch(value);
        clearTimeout(debounceRef.current);

        debounceRef.current = setTimeout(() => {
            if (value === filters.q) {
                return;
            }

            navigate({ ...filters, q: value }, true);
        }, SEARCH_DEBOUNCE_MS);
    }

    function handleTrackedChange(value: string) {
        clearTimeout(debounceRef.current);
        navigate({ q: search, tracked: value as ShowFilters['tracked'], sort: filters.sort }, false);
    }

    function handleSortChange(value: string) {
        clearTimeout(debounceRef.current);
        navigate({ q: search, tracked: filters.tracked, sort: value as ShowFilters['sort'] }, false);
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <div className="relative max-w-xs">
                <Input
                    placeholder="Search shows…"
                    value={search}
                    onChange={(e) => handleSearchChange(e.target.value)}
                    className={searching ? 'pr-8' : undefined}
                    aria-label="Search shows"
                />
                {searching && <Loader2 className="absolute top-1/2 right-2 size-4 -translate-y-1/2 animate-spin text-muted-foreground" />}
            </div>
            <Select value={filters.tracked} onValueChange={handleTrackedChange}>
                <SelectTrigger className="w-36" aria-label="Filter by tracked status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All shows</SelectItem>
                    <SelectItem value="yes">Tracked</SelectItem>
                    <SelectItem value="no">Untracked</SelectItem>
                </SelectContent>
            </Select>
            <Select value={filters.sort} onValueChange={handleSortChange}>
                <SelectTrigger className="w-40" aria-label="Sort shows">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="name">Sort: name</SelectItem>
                    <SelectItem value="last_seen">Sort: last seen</SelectItem>
                </SelectContent>
            </Select>
        </div>
    );
}
