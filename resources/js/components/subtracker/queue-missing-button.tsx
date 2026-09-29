import { Button } from '@/components/ui/button';
import { ACTION_HINTS } from '@/lib/hints';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { ActionHint } from './hint';

interface QueueMissingButtonProps {
    showId: number;
    downloadableCount: number;
}

export function QueueMissingButton({ showId, downloadableCount }: QueueMissingButtonProps) {
    const [pending, setPending] = useState(false);
    const disabled = pending || downloadableCount === 0;

    function handleClick() {
        setPending(true);
        router.post(
            `/shows/${showId}/queue-missing`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setPending(false),
            },
        );
    }

    return (
        // wrapDisabled keeps the tooltip working while the button itself is disabled
        <ActionHint hint={downloadableCount === 0 ? ACTION_HINTS.queueMissingNone : ACTION_HINTS.queueMissing} wrapDisabled>
            <Button variant="outline" size="sm" disabled={disabled} onClick={handleClick}>
                Queue missing ({downloadableCount})
            </Button>
        </ActionHint>
    );
}
