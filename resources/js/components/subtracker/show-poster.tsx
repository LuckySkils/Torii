import { cn } from '@/lib/utils';
import { type ImageStatus } from '@/types/subtracker';
import { ImageOff, Loader2 } from 'lucide-react';
import { useState } from 'react';

interface ShowPosterProps {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageWidth?: number | null;
    imageHeight?: number | null;
    name: string;
    className?: string;
}

export function ShowPoster({ imageUrl, imageStatus, imageWidth, imageHeight, name, className }: ShowPosterProps) {
    const [failed, setFailed] = useState(false);
    const showImage = imageUrl !== null && !failed;
    const aspectRatio = imageWidth && imageHeight ? `${imageWidth} / ${imageHeight}` : '2 / 3';

    return (
        <div className={cn('relative w-full shrink-0 overflow-hidden rounded-md bg-muted', className)} style={{ aspectRatio }}>
            {showImage ? (
                <img
                    src={imageUrl}
                    alt={name}
                    loading="lazy"
                    style={{ imageRendering: 'auto' }}
                    className="h-full w-full object-cover"
                    onError={() => setFailed(true)}
                />
            ) : imageStatus === 'missing' || imageStatus === 'error' ? (
                <div className="flex h-full w-full items-center justify-center text-muted-foreground">
                    <ImageOff className="size-1/3" />
                </div>
            ) : (
                <div className="line-clamp-4 flex h-full w-full items-center justify-center p-1.5 text-center text-xs leading-tight font-medium break-words text-muted-foreground">
                    {name}
                </div>
            )}

            {imageStatus === 'pending' && (
                <div className="absolute inset-0 flex items-center justify-center bg-background/60">
                    <Loader2 className="size-4 animate-spin text-muted-foreground" />
                </div>
            )}
        </div>
    );
}
