import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { TOUCH_TARGET_SM } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useState } from 'react';

interface DownloadButtonProps {
    releaseId: number;
    title: string;
    retry?: boolean;
    /** 'icon' (default): compact icon-only, used in dense table rows. 'full': labeled button, sized for touch. */
    variant?: 'icon' | 'full';
}

export function DownloadButton({ releaseId, title, retry = false, variant = 'icon' }: DownloadButtonProps) {
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

    if (variant === 'full') {
        return (
            <Button variant="outline" size="sm" className={`gap-1.5 ${TOUCH_TARGET_SM}`} disabled={pending} onClick={handleClick}>
                <Download className="size-3.5" />
                {retry ? 'Retry' : 'Download'}
            </Button>
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
