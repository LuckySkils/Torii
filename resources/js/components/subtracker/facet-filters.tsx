import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { formatLabel } from '@/lib/anime';
import { cn, TOUCH_TARGET_MD, TOUCH_TARGET_SM } from '@/lib/utils';
import { type FacetOption } from '@/types/subtracker';
import { ChevronDown, Minus, Plus, X } from 'lucide-react';
import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';

/** Matches the dropdown's w-64. */
const DROPDOWN_WIDTH_PX = 256;

/** Options plus any selected value the facet no longer lists (count 0), so it can still be unticked. */
function withSelected(options: FacetOption[], selected: string[]): FacetOption[] {
    const known = new Set(options.map((option) => option.value));

    return [...options, ...selected.filter((value) => !known.has(value)).map((value) => ({ value, count: 0 }))];
}

interface FormatFilterProps {
    options: FacetOption[];
    selected: string[];
    onChange: (next: string[]) => void;
    /** Render the checklist in place (filters sheet) instead of behind a button. */
    inline?: boolean;
    className?: string;
}

/** Multi-select over AniList formats: matches anything with any of the ticked formats. */
export function FormatFilter({ options, selected, onChange, inline = false, className }: FormatFilterProps) {
    const rows = withSelected(options, selected);

    function toggle(value: string, checked: boolean) {
        onChange(checked ? [...selected, value] : selected.filter((item) => item !== value));
    }

    const list = (
        <div className="flex flex-col">
            {rows.length === 0 && <p className="px-2 py-1.5 text-sm text-muted-foreground">No formats known yet.</p>}
            {rows.map((option) => (
                <label key={option.value} className="flex cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-sm hover:bg-muted">
                    <Checkbox checked={selected.includes(option.value)} onCheckedChange={(checked) => toggle(option.value, checked === true)} />
                    <span className="flex-1">{formatLabel(option.value)}</span>
                    <span className="text-xs text-muted-foreground tabular-nums">{option.count}</span>
                </label>
            ))}
        </div>
    );

    if (inline) {
        return <div className={cn('rounded-md border p-1', className)}>{list}</div>;
    }

    const summary =
        selected.length === 0 ? 'Any format' : selected.length === 1 ? formatLabel(selected[0]) : `${selected.length} formats`;

    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="outline" className={cn('justify-between gap-2 font-normal', selected.length > 0 && 'border-primary/50', className)} aria-label="Filter by format">
                    <span className="truncate">{summary}</span>
                    <ChevronDown className="size-4 opacity-50" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="start" className="w-56 p-1">
                {list}
                {selected.length > 0 && (
                    <Button variant="ghost" size="sm" className="mt-1 w-full" onClick={() => onChange([])}>
                        Any format
                    </Button>
                )}
            </PopoverContent>
        </Popover>
    );
}

interface GenreFilterProps {
    options: FacetOption[];
    include: string[];
    exclude: string[];
    onChange: (include: string[], exclude: string[]) => void;
    /** Filters sheet: the suggestion list stays open below the input, and the chips render here too. */
    inline?: boolean;
    className?: string;
}

/**
 * Type-ahead over genres. Picking one includes it (results must have *all*
 * included genres); the minus button, or Shift+Enter, excludes it (results must
 * have *none* of the excluded ones).
 */
export function GenreFilter({ options, include, exclude, onChange, inline = false, className }: GenreFilterProps) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    // The dropdown is wider than the input: it opens rightwards unless that would leave the viewport.
    const [alignRight, setAlignRight] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);
    const listId = useId();

    function openList() {
        const rect = rootRef.current?.getBoundingClientRect();
        setAlignRight(rect !== undefined && rect.left + DROPDOWN_WIDTH_PX > window.innerWidth - 8);
        setOpen(true);
    }

    const suggestions = useMemo(() => {
        const needle = query.trim().toLowerCase();

        return options.filter(
            (option) => !include.includes(option.value) && !exclude.includes(option.value) && option.value.toLowerCase().includes(needle),
        );
    }, [options, include, exclude, query]);

    const listVisible = inline || open;

    function pick(value: string, mode: 'include' | 'exclude') {
        if (mode === 'include') {
            onChange([...include, value], exclude);
        } else {
            onChange(include, [...exclude, value]);
        }

        setQuery('');
        setActive(0);
    }

    function handleKeyDown(event: KeyboardEvent<HTMLInputElement>) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            openList();
            setActive((index) => Math.min(index + 1, suggestions.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        } else if (event.key === 'Enter') {
            const option = suggestions[active] ?? suggestions[0];

            if (option) {
                event.preventDefault();
                pick(option.value, event.shiftKey ? 'exclude' : 'include');
            }
        } else if (event.key === 'Escape') {
            setOpen(false);
        } else if (event.key === 'Backspace' && query === '' && (include.length > 0 || exclude.length > 0)) {
            // Backspace on an empty box drops the most recent chip, like a tag input.
            if (exclude.length > 0) {
                onChange(include, exclude.slice(0, -1));
            } else {
                onChange(include.slice(0, -1), exclude);
            }
        }
    }

    const activeOption = listVisible ? suggestions[active] : undefined;

    return (
        <div ref={rootRef} className={cn('relative flex flex-col gap-2', className)}>
            <Input
                role="combobox"
                aria-expanded={listVisible}
                aria-controls={listId}
                aria-activedescendant={activeOption ? `${listId}-${activeOption.value}` : undefined}
                aria-label="Filter by genre"
                placeholder={include.length + exclude.length > 0 ? 'Add genre…' : 'Genre…'}
                value={query}
                onChange={(event) => {
                    setQuery(event.target.value);
                    setActive(0);
                    openList();
                }}
                onFocus={openList}
                onBlur={() => setOpen(false)}
                onKeyDown={handleKeyDown}
            />

            {listVisible && (
                <div
                    className={cn(
                        'flex flex-col overflow-hidden rounded-md border bg-popover text-popover-foreground',
                        !inline && cn('absolute top-full z-50 mt-1 w-64 shadow-md', alignRight ? 'right-0' : 'left-0'),
                    )}
                    // Keeps focus in the input while clicking rows and buttons.
                    onMouseDown={(event) => event.preventDefault()}
                >
                    <ul id={listId} role="listbox" aria-label="Genres" className={cn('overflow-y-auto p-1', inline ? 'max-h-56' : 'max-h-72')}>
                        {suggestions.length === 0 && (
                            <li className="px-2 py-1.5 text-sm text-muted-foreground">{options.length === 0 ? 'No genres known yet.' : 'No matching genre.'}</li>
                        )}
                        {suggestions.map((option, index) => (
                            <li
                                key={option.value}
                                id={`${listId}-${option.value}`}
                                role="option"
                                aria-selected={index === active}
                                className={cn('flex items-center gap-2 rounded-sm py-0.5 pr-0.5 pl-2 text-sm', index === active && 'bg-muted')}
                                onMouseEnter={() => setActive(index)}
                            >
                                <button type="button" className="flex min-w-0 flex-1 cursor-pointer items-center gap-2 py-1 text-left" onClick={() => pick(option.value, 'include')}>
                                    <span className="truncate">{option.value}</span>
                                    <span className="ml-auto text-xs text-muted-foreground tabular-nums">{option.count}</span>
                                </button>
                                <button
                                    type="button"
                                    className={cn('flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-sm hover:bg-background', TOUCH_TARGET_SM)}
                                    aria-label={`Include ${option.value}`}
                                    title="Include: must have this genre"
                                    onClick={() => pick(option.value, 'include')}
                                >
                                    <Plus className="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    className={cn(
                                        'flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-sm text-destructive hover:bg-background',
                                        TOUCH_TARGET_SM,
                                    )}
                                    aria-label={`Exclude ${option.value}`}
                                    title="Exclude: must not have this genre"
                                    onClick={() => pick(option.value, 'exclude')}
                                >
                                    <Minus className="size-3.5" />
                                </button>
                            </li>
                        ))}
                    </ul>
                    <p className="hidden border-t px-2 py-1 text-[11px] text-muted-foreground [@media(hover:hover)]:block">
                        Enter includes · Shift+Enter excludes
                    </p>
                </div>
            )}

            {inline && <GenreChips include={include} exclude={exclude} onChange={onChange} />}
        </div>
    );
}

interface GenreChipsProps {
    include: string[];
    exclude: string[];
    onChange: (include: string[], exclude: string[]) => void;
    className?: string;
}

/** The chosen genres: "with all of" and "without". A chip's name flips it to the other side; × removes it. */
export function GenreChips({ include, exclude, onChange, className }: GenreChipsProps) {
    if (include.length === 0 && exclude.length === 0) {
        return null;
    }

    return (
        <div className={cn('flex flex-wrap items-center gap-x-3 gap-y-1.5 text-sm', className)}>
            {include.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-muted-foreground">{include.length > 1 ? 'With all of' : 'With'}</span>
                    {include.map((genre) => (
                        <GenreChip
                            key={genre}
                            genre={genre}
                            mode="include"
                            onFlip={() => onChange(include.filter((item) => item !== genre), [...exclude, genre])}
                            onRemove={() => onChange(include.filter((item) => item !== genre), exclude)}
                        />
                    ))}
                </div>
            )}
            {exclude.length > 0 && (
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="text-xs text-muted-foreground">Without</span>
                    {exclude.map((genre) => (
                        <GenreChip
                            key={genre}
                            genre={genre}
                            mode="exclude"
                            onFlip={() => onChange([...include, genre], exclude.filter((item) => item !== genre))}
                            onRemove={() => onChange(include, exclude.filter((item) => item !== genre))}
                        />
                    ))}
                </div>
            )}
            {include.length + exclude.length > 1 && (
                <button type="button" className="cursor-pointer text-xs text-muted-foreground hover:text-foreground hover:underline" onClick={() => onChange([], [])}>
                    Clear genres
                </button>
            )}
        </div>
    );
}

function GenreChip({ genre, mode, onFlip, onRemove }: { genre: string; mode: 'include' | 'exclude'; onFlip: () => void; onRemove: () => void }) {
    const include = mode === 'include';

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-full border text-xs',
                include ? 'border-primary/40 bg-primary/5' : 'border-destructive/40 bg-destructive/5 text-destructive',
            )}
        >
            <button
                type="button"
                className={cn('inline-flex cursor-pointer items-center gap-1 py-0.5 pr-1 pl-2', !include && 'line-through decoration-1')}
                onClick={onFlip}
                title={include ? 'Switch to exclude' : 'Switch to include'}
                aria-label={`${genre}: ${include ? 'included' : 'excluded'}; switch to ${include ? 'exclude' : 'include'}`}
            >
                {include ? <Plus className="size-3" /> : <Minus className="size-3" />}
                {genre}
            </button>
            <button
                type="button"
                className={cn('flex size-5 cursor-pointer items-center justify-center rounded-full hover:bg-muted', TOUCH_TARGET_MD)}
                onClick={onRemove}
                aria-label={`Remove ${genre}`}
            >
                <X className="size-3" />
            </button>
        </span>
    );
}
