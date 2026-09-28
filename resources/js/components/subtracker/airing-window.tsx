import { formatRelative } from '@/lib/dates';
import { cn } from '@/lib/utils';
import { type AiringWindow, type AiringWindowEntry } from '@/types/subtracker';

const dayDate = new Intl.DateTimeFormat(undefined, { weekday: 'short', day: 'numeric', month: 'short' });
const time = new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' });

const CELLS = [
    { key: 'previous', label: 'Last aired', empty: 'Nothing aired yet' },
    { key: 'current', label: 'Next up', empty: 'Nothing scheduled' },
    { key: 'next', label: 'After that', empty: 'Not scheduled yet' },
] as const;

/**
 * Previous / current / next airing as three cells, "Next up" highlighted.
 * Any of them can be null (not started, finished, or no schedule beyond the
 * next episode); the strip is hidden only when all three are.
 */
export function AiringWindowStrip({ airingWindow, className }: { airingWindow: AiringWindow; className?: string }) {
    if (!airingWindow.previous && !airingWindow.current && !airingWindow.next) {
        return null;
    }

    return (
        <div className={cn('grid grid-cols-3 gap-2', className)} role="group" aria-label="Airing schedule around now">
            {CELLS.map((cell) => (
                <WindowCell key={cell.key} label={cell.label} empty={cell.empty} entry={airingWindow[cell.key]} highlight={cell.key === 'current'} />
            ))}
        </div>
    );
}

function WindowCell({ label, empty, entry, highlight }: { label: string; empty: string; entry: AiringWindowEntry | null; highlight: boolean }) {
    const date = entry ? new Date(entry.airsAt) : null;
    const nextUp = highlight && entry !== null;

    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-0.5 rounded-lg border px-2.5 py-2 text-sm large:md:px-3.5 large:md:py-3 large:md:text-base',
                nextUp ? 'border-sky-500/50 bg-sky-500/5' : entry === null && 'border-dashed',
            )}
            aria-current={nextUp ? 'true' : undefined}
        >
            <span
                className={cn(
                    'text-[11px] font-medium tracking-wide uppercase large:md:text-xs',
                    nextUp ? 'text-sky-700 dark:text-sky-300' : 'text-muted-foreground',
                )}
            >
                {label}
            </span>
            {entry && date ? (
                <>
                    <span className="font-medium tabular-nums">Ep {entry.episode}</span>
                    <time dateTime={entry.airsAt} className="truncate text-xs text-muted-foreground large:md:text-sm" title={date.toLocaleString()}>
                        {dayDate.format(date)}, {time.format(date)}
                    </time>
                    <span className={cn('truncate text-xs large:md:text-sm', nextUp ? 'text-sky-700 dark:text-sky-300' : 'text-muted-foreground')}>
                        {formatRelative(entry.airsAt)}
                    </span>
                </>
            ) : (
                <span className="text-xs text-muted-foreground large:md:text-sm">{empty}</span>
            )}
        </div>
    );
}
