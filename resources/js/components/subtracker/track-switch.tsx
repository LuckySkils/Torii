import { Switch } from '@/components/ui/switch';
import { ACTION_HINTS } from '@/lib/hints';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ActionHint } from './hint';
import { TrackConfirmDialog } from './track-confirm-dialog';

interface TrackSwitchProps {
    showId: number;
    showName: string;
    tracked: boolean;
    downloadableCount: number;
    hasBatch: boolean;
    /** Explain what tracking does (show page). Off on cards and rows, where the column header explains it once. */
    withHint?: boolean;
}

export function TrackSwitch({ showId, showName, tracked, downloadableCount, hasBatch, withHint = false }: TrackSwitchProps) {
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
            {withHint ? (
                <ActionHint hint={ACTION_HINTS.track}>
                    <Switch checked={checked} disabled={pending} onCheckedChange={handleChange} aria-label={`Track ${showName}`} />
                </ActionHint>
            ) : (
                <Switch checked={checked} disabled={pending} onCheckedChange={handleChange} aria-label={`Track ${showName}`} />
            )}
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
