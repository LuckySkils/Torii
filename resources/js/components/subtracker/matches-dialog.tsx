import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { type MatchingArticles } from '@/types/subtracker';
import { useState } from 'react';

interface MatchesDialogProps {
    showId: number;
    showName: string;
    /** Self-managed mode: renders this as the DialogTrigger. Omit it and pass open/onOpenChange for controlled mode instead. */
    trigger?: React.ReactNode;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
}

type LoadState = { status: 'idle' } | { status: 'loading' } | { status: 'error'; message: string } | { status: 'loaded'; data: MatchingArticles };

export function MatchesDialog({ showId, showName, trigger, open, onOpenChange }: MatchesDialogProps) {
    const [state, setState] = useState<LoadState>({ status: 'idle' });
    const [internalOpen, setInternalOpen] = useState(false);
    const isControlled = open !== undefined;
    const resolvedOpen = isControlled ? open : internalOpen;

    async function handleOpenChange(next: boolean) {
        if (isControlled) {
            onOpenChange?.(next);
        } else {
            setInternalOpen(next);
        }

        if (!next) {
            return;
        }

        setState({ status: 'loading' });

        try {
            const response = await fetch(`/shows/${showId}/matches`, {
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                throw new Error(`Request failed with status ${response.status}`);
            }

            const data: MatchingArticles = await response.json();
            setState({ status: 'loaded', data });
        } catch (error) {
            setState({ status: 'error', message: error instanceof Error ? error.message : 'Failed to load matches' });
        }
    }

    const feeds = state.status === 'loaded' ? Object.entries(state.data) : [];
    const hasAnyMatches = feeds.some(([, articles]) => articles.length > 0);

    return (
        <Dialog open={resolvedOpen} onOpenChange={handleOpenChange}>
            {trigger && <DialogTrigger asChild>{trigger}</DialogTrigger>}
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Matching articles for "{showName}"</DialogTitle>
                </DialogHeader>

                {state.status === 'loading' && <p className="text-sm text-muted-foreground">Loading…</p>}

                {state.status === 'error' && <p className="text-sm text-destructive">{state.message}</p>}

                {state.status === 'loaded' && !hasAnyMatches && <p className="text-sm text-muted-foreground">No matching articles found.</p>}

                {state.status === 'loaded' && hasAnyMatches && (
                    <div className="flex flex-col gap-4">
                        {feeds.map(([feedName, articles]) =>
                            articles.length === 0 ? null : (
                                <div key={feedName} className="flex flex-col gap-1">
                                    <h3 className="text-sm font-medium">{feedName}</h3>
                                    <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
                                        {articles.map((article) => (
                                            <li key={article} className="truncate" title={article}>
                                                {article}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ),
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
