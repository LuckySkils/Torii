import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { type ImageStatus } from '@/types/subtracker';
import { useState } from 'react';
import { ShowPoster } from './show-poster';

interface PosterHoverPreviewProps {
    imageUrl: string | null;
    imageStatus: ImageStatus;
    imageWidth: number | null;
    imageHeight: number | null;
    name: string;
}

export function PosterHoverPreview({ imageUrl, imageStatus, imageWidth, imageHeight, name }: PosterHoverPreviewProps) {
    const [open, setOpen] = useState(false);

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <button
                    type="button"
                    className="block"
                    onMouseEnter={() => setOpen(true)}
                    onMouseLeave={() => setOpen(false)}
                    onFocus={() => setOpen(true)}
                    onBlur={() => setOpen(false)}
                    aria-label={`Preview poster for ${name}`}
                >
                    <ShowPoster
                        imageUrl={imageUrl}
                        imageStatus={imageStatus}
                        imageWidth={imageWidth}
                        imageHeight={imageHeight}
                        name={name}
                        className="w-16"
                    />
                </button>
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
