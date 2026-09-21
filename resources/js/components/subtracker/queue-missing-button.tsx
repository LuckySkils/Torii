import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { router } from '@inertiajs/react';
import { useState } from 'react';

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
        <Tooltip>
            <TooltipTrigger asChild>
                {/* span wrapper keeps the tooltip working while the button itself is disabled */}
                <span tabIndex={0}>
                    <Button variant="outline" size="sm" disabled={disabled} onClick={handleClick}>
                        Queue missing ({downloadableCount})
                    </Button>
                </span>
            </TooltipTrigger>
            <TooltipContent>{downloadableCount === 0 ? 'Nothing new to queue' : `Queue ${downloadableCount} releases`}</TooltipContent>
        </Tooltip>
    );
}
