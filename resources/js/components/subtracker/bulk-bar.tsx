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
        <div className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/50 px-3 py-2">
            <span className="text-sm">{selectedIds.length} selected</span>
            <Button size="sm" disabled={pending !== null} onClick={handleTrackClick}>
                Track selected
            </Button>
            <Button size="sm" variant="outline" disabled={pending !== null} onClick={() => commit(false)}>
                Untrack selected
            </Button>
            <Button size="sm" variant="ghost" onClick={onClear}>
                Clear selection
            </Button>
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
