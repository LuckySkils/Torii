import { cn } from '@/lib/utils';
import { type ImageStatus } from '@/types/subtracker';
import { ImageOff, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ShowPosterProps {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    name: string;
    className?: string;
}

function initials(name: string): string {
    return name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0]?.toUpperCase() ?? '')
        .join('');
}

export function ShowPoster({ imageUrl, imageStatus, name, className }: ShowPosterProps) {
    const [failed, setFailed] = useState(false);
    const showImage = imageUrl !== null && !failed;

    return (
        <div className={cn('relative aspect-[2/3] shrink-0 overflow-hidden rounded-md bg-muted', className)}>
            {showImage ? (
                <img src={imageUrl} alt={name} loading="lazy" className="h-full w-full object-cover" onError={() => setFailed(true)} />
            ) : imageStatus === 'missing' || imageStatus === 'error' ? (
                <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                    <ImageOff className="size-1/3" />
                </div>
            ) : (
                <div className="flex h-full w-full items-center justify-center text-sm font-medium text-muted-foreground">{initials(name)}</div>
            )}

            {imageStatus === 'pending' && (
                <div className="absolute inset-0 flex items-center justify-center bg-background/60">
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                </div>
            )}
        </div>
    );
}
