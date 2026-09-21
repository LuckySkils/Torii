import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';

interface DownloadButtonProps {
    releaseId: number;
    title: string;
    retry?: boolean;
}

export function DownloadButton({ releaseId, title, retry = false }: DownloadButtonProps) {
    const [pending, setPending] = useState(false);
    const label = retry ? `Retry download for ${title}` : `Download ${title}`;

    function handleClick() {
        setPending(true);
        router.post(
            `/releases/${releaseId}/download`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setPending(false),
            },
        );
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button variant="ghost" size="icon" className="size-8" aria-label={label} disabled={pending} onClick={handleClick}>
                    <Download className="size-4" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}
