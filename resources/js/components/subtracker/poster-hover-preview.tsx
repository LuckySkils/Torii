import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { type ImageStatus } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { useState } from 'react';
import { ShowPoster } from './show-poster';

interface PosterHoverPreviewProps {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageWidth?: number | null;
    imageHeight?: number | null;
    name: string;
    /** Width class of the small inline poster. */
    thumbClassName?: string;
    /** When set, the thumbnail links here instead of being a plain button. */
    href?: string;
}

export function PosterHoverPreview({ imageUrl, imageStatus, imageWidth, imageHeight, name, thumbClassName = 'w-16', href }: PosterHoverPreviewProps) {
    const [open, setOpen] = useState(false);

    const handlers = {
        onMouseEnter: () => setOpen(true),
        onMouseLeave: () => setOpen(false),
        onFocus: () => setOpen(true),
        onBlur: () => setOpen(false),
    };

    const thumb = (
        <ShowPoster imageUrl={imageUrl} imageStatus={imageStatus} imageWidth={imageWidth} imageHeight={imageHeight} name={name} className={thumbClassName} />
    );

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                {href ? (
                    <Link href={href} className="block" aria-label={`Open ${name}`} {...handlers}>
                        {thumb}
                    </Link>
                ) : (
                    <button type="button" className="block" aria-label={`Preview poster for ${name}`} {...handlers}>
                        {thumb}
                    </button>
                )}
            </PopoverTrigger>
            <PopoverContent
                side="right"
                className="w-auto p-1"
                onOpenAutoFocus={(e) => e.preventDefault()}
                onCloseAutoFocus={(e) => e.preventDefault()}
            >
                <ShowPoster
                    imageUrl={imageUrl}
                    imageStatus={imageStatus}
                    imageWidth={imageWidth}
                    imageHeight={imageHeight}
                    name={name}
                    className="w-60"
                />
            </PopoverContent>
        </Popover>
    );
}
