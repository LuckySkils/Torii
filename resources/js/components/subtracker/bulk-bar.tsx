import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';

interface BulkBarProps {
    selectedIds: number[];
    onClear: () => void;
}

export function BulkBar({ selectedIds, onClear }: BulkBarProps) {
    const [pending, setPending] = useState<'track' | 'untrack' | null>(null);

    if (selectedIds.length === 0) {
        return null;
    }

    function bulkUpdate(tracked: boolean) {
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

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-md border bg-muted/50 px-3 py-2">
            <span className="text-sm">{selectedIds.length} selected</span>
            <Button size="sm" disabled={pending !== null} onClick={() => bulkUpdate(true)}>
                Track selected
            </Button>
            <Button size="sm" variant="outline" disabled={pending !== null} onClick={() => bulkUpdate(false)}>
                Untrack selected
            </Button>
            <Button size="sm" variant="ghost" onClick={onClear}>
                Clear selection
            </Button>
        </div>
    );
}
