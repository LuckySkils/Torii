import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type PremiereSource, type Season } from '@/types/subtracker';

const SEASON_LABEL: Record<Season, string> = {
    winter: 'Winter',
    spring: 'Spring',
    summer: 'Summer',
    autumn: 'Autumn',
};

interface SeasonLabelProps {
    season: Season | null;
    seasonYear: number | null;
    premiereSource: PremiereSource | null;
    className?: string;
}

export function SeasonLabel({ season, seasonYear, premiereSource, className }: SeasonLabelProps) {
    if (season === null || seasonYear === null) {
        return null;
    }

    const text = `${SEASON_LABEL[season]} ${seasonYear}`;

    if (premiereSource !== 'earliest_seen') {
        return <span className={className}>{text}</span>;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className={className}>{text} (approx.)</span>
            </TooltipTrigger>
            <TooltipContent>Premiere date estimated from when Torii first saw this show</TooltipContent>
        </Tooltip>
    );
}
