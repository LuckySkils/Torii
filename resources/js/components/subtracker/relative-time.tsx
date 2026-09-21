import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { formatAbsolute, formatRelative } from '@/lib/dates';

interface RelativeTimeProps {
    iso: string;
    className?: string;
}

export function RelativeTime({ iso, className }: RelativeTimeProps) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <time dateTime={iso} className={className}>
                    {formatRelative(iso)}
                </time>
            </TooltipTrigger>
            <TooltipContent>{formatAbsolute(iso)}</TooltipContent>
        </Tooltip>
    );
}
