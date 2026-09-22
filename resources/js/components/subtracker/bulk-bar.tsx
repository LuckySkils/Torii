import { Button } from '@/components/ui/button';
import { type ShowSummary } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { TrackConfirmDialog } from './track-confirm-dialog';

interface BulkBarProps {
    shows: ShowSummary[];
    selectedIds: number[];
    onClear: () => void;
}

export function BulkBar({ shows, selectedIds, onClear }: BulkBarProps) {
    const [pending, setPending] = useState<'track' | 'untrack' | null>(null);
    const [confirmOpen, setConfirmOpen] = useState(false);

    if (selectedIds.length === 0) {
        return null;
    }

    const selectedShows = shows.filter((show) => selectedIds.includes(show.id));
    const totalDownloadable = selectedShows.reduce((sum, show) => sum + show.downloadableCount, 0);
    const anyHasBatch = selectedShows.some((show) => show.hasBatch);

    function commit(tracked: boolean) {
        setPending(tracked ? 'track' : 'untrack');

        router.post(
            '/shows/track-bulk',
            { ids: selectedIds, tracked },
            {
                preserveScroll: true,
                onSuccess: onClear,
                onError: () => toast.error('Bulk update failed.'),
                onFinish: () => setPending(null),
            },
        );
    }

    function handleTrackClick() {
        if (totalDownloadable > 0) {
            setConfirmOpen(true);
            return;
        }

        commit(true);
    }

    return (
        <div
            className={
                'fixed inset-x-0 bottom-0 z-40 flex flex-col gap-2 border-t bg-background p-3 shadow-lg ' +
                'pb-[calc(0.75rem+env(safe-area-inset-bottom))] ' +
                'sm:static sm:inset-auto sm:z-auto sm:flex-row sm:flex-wrap sm:items-center sm:rounded-md sm:border sm:bg-muted/50 sm:px-3 sm:py-2 sm:pb-2 sm:shadow-none'
            }
        >
            <span className="text-sm">{selectedIds.length} selected</span>
            <div className="grid grid-cols-3 gap-2 sm:contents">
                <Button disabled={pending !== null} onClick={handleTrackClick} className="sm:h-9 sm:px-3 sm:text-sm">
                    <span className="sm:hidden">Track</span>
                    <span className="hidden sm:inline">Track selected</span>
                </Button>
                <Button variant="outline" disabled={pending !== null} onClick={() => commit(false)} className="sm:h-9 sm:px-3 sm:text-sm">
                    <span className="sm:hidden">Untrack</span>
                    <span className="hidden sm:inline">Untrack selected</span>
                </Button>
                <Button variant="ghost" onClick={onClear} className="sm:h-9 sm:px-3 sm:text-sm">
                    <span className="sm:hidden">Cancel</span>
                    <span className="hidden sm:inline">Clear selection</span>
                </Button>
            </div>
            <TrackConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                description={`This will queue ${totalDownloadable} release${totalDownloadable === 1 ? '' : 's'} for download across ${selectedShows.length} show${selectedShows.length === 1 ? '' : 's'}${anyHasBatch ? ', some as batches' : ''}.`}
                onConfirm={() => {
                    setConfirmOpen(false);
                    commit(true);
                }}
            />
        </div>
    );
}
