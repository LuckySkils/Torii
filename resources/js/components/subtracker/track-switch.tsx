import { Switch } from '@/components/ui/switch';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { TrackConfirmDialog } from './track-confirm-dialog';

interface TrackSwitchProps {
    showId: number;
    showName: string;
    tracked: boolean;
    downloadableCount: number;
    hasBatch: boolean;
}

export function TrackSwitch({ showId, showName, tracked, downloadableCount, hasBatch }: TrackSwitchProps) {
    const [checked, setChecked] = useState(tracked);
    const [pending, setPending] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    useEffect(() => {
        setChecked(tracked);
    }, [tracked]);

    function commit(next: boolean) {
        setChecked(next);
        setPending(true);

        router.patch(
            `/shows/${showId}/track`,
            { tracked: next },
            {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    setChecked(!next);
                    toast.error(`Couldn't update tracking for "${showName}".`);
                },
                onFinish: () => setPending(false),
            },
        );
    }

    function handleChange(next: boolean) {
        if (next && downloadableCount > 0) {
            setConfirmOpen(true);
            return;
        }

        commit(next);
    }

    return (
        <>
            <Switch checked={checked} disabled={pending} onCheckedChange={handleChange} aria-label={`Track ${showName}`} />
            <TrackConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                description={`This will queue ${downloadableCount} release${downloadableCount === 1 ? '' : 's'} for download${hasBatch ? ' as one batch' : ''}.`}
                onConfirm={() => {
                    setConfirmOpen(false);
                    commit(true);
                }}
            />
        </>
    );
}
