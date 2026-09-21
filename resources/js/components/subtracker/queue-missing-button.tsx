import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { useState } from 'react';

interface QueueMissingButtonProps {
    showId: number;
    downloadableCount: number;
}

export function QueueMissingButton({ showId, downloadableCount }: QueueMissingButtonProps) {
    const [pending, setPending] = useState(false);

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
        <Button variant="outline" size="sm" disabled={pending || downloadableCount === 0} onClick={handleClick}>
            Queue missing ({downloadableCount})
        </Button>
    );
}
