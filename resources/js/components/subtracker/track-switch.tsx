import { Switch } from '@/components/ui/switch';
import { ACTION_HINTS } from '@/lib/hints';
import { fetchShowQueueInfo, QueueInfoError, type ShowQueueInfo } from '@/lib/show-queue-info';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { usePinCard } from './card-pin';
import { ActionHint } from './hint';
import { TrackConfirmDialog } from './track-confirm-dialog';

interface TrackSwitchProps {
    showId: number;
    showName: string;
    tracked: boolean;
    /**
     * What tracking would queue, for the confirm dialog. Pages whose props don't
     * carry it (the anime page, the detail card) leave both out: the switch then
     * asks the show page for them when it's turned on.
     */
    downloadableCount?: number;
    hasBatch?: boolean;
    /** Explain what tracking does (show page). Off on cards and rows, where the column header explains it once. */
    withHint?: boolean;
    /** Called once the server has accepted the change. */
    onTracked?: (tracked: boolean) => void;
}

export function TrackSwitch({ showId, showName, tracked, downloadableCount, hasBatch, withHint = false, onTracked }: TrackSwitchProps) {
    const { version } = usePage();
    const [checked, setChecked] = useState(tracked);
    const [pending, setPending] = useState(false);
    const [confirm, setConfirm] = useState<ShowQueueInfo | null>(null);
    const pinCard = usePinCard();

    useEffect(() => {
        setChecked(tracked);
    }, [tracked]);

    // Inside a hover card, keep it open while the confirm dialog is showing.
    useEffect(() => {
        pinCard?.(confirm !== null);
    }, [confirm, pinCard]);

    function commit(next: boolean) {
        setChecked(next);
        setPending(true);

        router.patch(
            `/shows/${showId}/track`,
            { tracked: next },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => onTracked?.(next),
                onError: () => {
                    setChecked(!next);
                    toast.error(`Couldn't update tracking for "${showName}".`);
                },
                onFinish: () => setPending(false),
            },
        );
    }

    async function queueInfo(): Promise<ShowQueueInfo> {
        if (downloadableCount !== undefined) {
            return { downloadableCount, hasBatch: hasBatch ?? false };
        }

        setPending(true);

        try {
            return await fetchShowQueueInfo(showId, version);
        } finally {
            setPending(false);
        }
    }

    async function handleChange(next: boolean) {
        if (!next) {
            commit(false);
            return;
        }

        try {
            const info = await queueInfo();

            if (info.downloadableCount > 0) {
                setConfirm(info);
            } else {
                commit(true);
            }
        } catch (error) {
            // Never track without the confirmation when it can't be shown.
            toast.error(error instanceof QueueInfoError ? error.message : `Couldn't start tracking "${showName}".`);
        }
    }

    const control = <Switch checked={checked} disabled={pending} onCheckedChange={handleChange} aria-label={`Track ${showName}`} />;

    return (
        <>
            {withHint ? <ActionHint hint={ACTION_HINTS.track}>{control}</ActionHint> : control}
            <TrackConfirmDialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
                description={
                    confirm
                        ? `This will queue ${confirm.downloadableCount} release${confirm.downloadableCount === 1 ? '' : 's'} for download${confirm.hasBatch ? ' as one batch' : ''}.`
                        : ''
                }
                onConfirm={() => {
                    setConfirm(null);
                    commit(true);
                }}
            />
        </>
    );
}
