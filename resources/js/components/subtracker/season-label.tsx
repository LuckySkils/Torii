import { cn } from '@/lib/utils';
import { type PremiereSource, type Season } from '@/types/subtracker';
import { TapInfo } from './tap-info';

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
        <TapInfo trigger={<button type="button" className={cn('cursor-pointer bg-transparent p-0 text-left font-inherit', className)}>{text} (approx.)</button>}>
            Premiere date estimated from when Torii first saw this show
        </TapInfo>
    );
}
