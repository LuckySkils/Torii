import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { getJson, HttpError, postJson } from '@/lib/http';
import { cn, TOUCH_TARGET_SM } from '@/lib/utils';
import {
    type NyaaConfirmResult,
    type NyaaPreview,
    type NyaaPreviewItem,
    type NyaaSeries,
    type NyaaShowSearch,
    type NyaaTarget,
} from '@/types/subtracker';
import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Check,
    ChevronDown,
    ChevronRight,
    ExternalLink,
    Import,
    Info,
    Loader2,
    Plus,
    Search,
    ShieldCheck,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useId, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { toast } from 'sonner';
import { Hint } from './hint';

/** "Import from Nyaa": the button that opens the dialog, sized like the toolbar buttons around it. */
export function NyaaImportButton({ className, label = 'Import from Nyaa' }: { className?: string; label?: string }) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Button variant="outline" className={cn('gap-1.5', className)} onClick={() => setOpen(true)}>
                <Import className="size-4" />
                {label}
            </Button>
            <NyaaImportDialog open={open} onOpenChange={setOpen} />
        </>
    );
}

const DESCRIPTION = 'Add episodes from a Nyaa search to qBittorrent. No rule is created and nothing gets tracked.';

/**
 * A tap inside a tap-info popover isn't "outside" the dialog. Radix's dialog and
 * popover bundle separate layer stacks, so the dialog can't tell on its own.
 */
function keepOpenForPopovers(event: { target: EventTarget | null; preventDefault: () => void }) {
    if (event.target instanceof Element && event.target.closest('[data-radix-popper-content-wrapper]')) {
        event.preventDefault();
    }
}

/**
 * Dialog on wider screens, full-height sheet on phones. The flow lives inside the
 * content, which unmounts on close: closing always discards the preview.
 */
export function NyaaImportDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const mobile = useIsMobile();
    const close = () => onOpenChange(false);

    if (mobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent
                    side="bottom"
                    onInteractOutside={keepOpenForPopovers}
                    className="flex h-[100dvh] max-h-[100dvh] flex-col gap-0 p-0 pt-[env(safe-area-inset-top)]"
                >
                    <SheetHeader className="gap-1 border-b p-3 pr-12 text-left">
                        <SheetTitle>Import from Nyaa</SheetTitle>
                        <SheetDescription className="sr-only">{DESCRIPTION}</SheetDescription>
                    </SheetHeader>
                    <ImportFlow onClose={close} />
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent onInteractOutside={keepOpenForPopovers} className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-4xl">
                <DialogHeader className="border-b p-6 pb-4">
                    <DialogTitle>Import from Nyaa</DialogTitle>
                    <DialogDescription>{DESCRIPTION}</DialogDescription>
                </DialogHeader>
                <ImportFlow onClose={close} />
            </DialogContent>
        </Dialog>
    );
}

type Step = { kind: 'url' } | { kind: 'preview'; preview: NyaaPreview } | { kind: 'done'; result: NyaaConfirmResult };

/** What a retarget asks for: an existing show, or a show created with this name on confirm. */
type TargetChoice = { showId: number } | { newName: string };

/** Keys the user can tick: every item of a series. Unparsed items have no show to go to. */
function selectableKeys(preview: NyaaPreview): Set<string> {
    return new Set(preview.series.flatMap((series) => series.itemKeys));
}

function suggestedKeys(preview: NyaaPreview): string[] {
    const selectable = selectableKeys(preview);

    return preview.items.filter((item) => item.selected && selectable.has(item.key)).map((item) => item.key);
}

function ImportFlow({ onClose }: { onClose: () => void }) {
    const [url, setUrl] = useState('');
    const [step, setStep] = useState<Step>({ kind: 'url' });
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState<'preview' | 'confirm' | null>(null);
    /** The series whose target is being changed; its section is disabled meanwhile. */
    const [retargeting, setRetargeting] = useState<string | null>(null);
    const [error, setError] = useState<{ message: string; expired: boolean } | null>(null);
    const controllerRef = useRef<AbortController | null>(null);

    // Closing the dialog unmounts this: a preview still loading is abandoned.
    useEffect(() => () => controllerRef.current?.abort(), []);

    async function preview(event?: FormEvent) {
        event?.preventDefault();

        if (!url.trim()) {
            return;
        }

        controllerRef.current?.abort();
        const controller = new AbortController();
        controllerRef.current = controller;
        setBusy('preview');
        setError(null);

        try {
            const result = await postJson<NyaaPreview>('/import/nyaa/preview', { url: url.trim() }, controller.signal);
            setSelected(new Set(suggestedKeys(result)));
            setStep({ kind: 'preview', preview: result });
        } catch (caught) {
            if (controller.signal.aborted) {
                return;
            }

            setError({ message: caught instanceof HttpError ? caught.message : 'The preview failed.', expired: false });
        } finally {
            if (!controller.signal.aborted) {
                setBusy(null);
            }
        }
    }

    async function retarget(current: NyaaPreview, seriesKey: string, target: TargetChoice) {
        setRetargeting(seriesKey);

        try {
            const next = await postJson<NyaaPreview>(`/import/nyaa/preview/${encodeURIComponent(current.previewId)}/target`, { seriesKey, target });
            // The response is the whole preview, states and suggested selection recomputed.
            // Only this series' items can change, so they take the new suggestion; the
            // other series keep whatever was ticked by hand.
            const changed = new Set(next.series.find((series) => series.key === seriesKey)?.itemKeys ?? []);
            const selectable = selectableKeys(next);
            setSelected((previous) => {
                const merged = new Set<string>();

                for (const item of next.items) {
                    if (selectable.has(item.key) && (changed.has(item.key) ? item.selected : previous.has(item.key))) {
                        merged.add(item.key);
                    }
                }

                return merged;
            });
            setStep({ kind: 'preview', preview: next });
        } catch (caught) {
            if (caught instanceof HttpError && caught.fields.includes('previewId')) {
                setStep({ kind: 'url' });
                setError({ message: caught.message, expired: false });
                return;
            }

            toast.error(caught instanceof HttpError ? caught.message : "Couldn't change where these releases go.");
        } finally {
            setRetargeting(null);
        }
    }

    async function confirm(previewId: string) {
        setBusy('confirm');
        setError(null);

        try {
            const result = await postJson<NyaaConfirmResult>('/import/nyaa/confirm', { previewId, keys: [...selected] });
            setStep({ kind: 'done', result });
            // New releases, and possibly a new show, for whatever page is underneath.
            router.reload();
        } catch (caught) {
            const expired = caught instanceof HttpError && caught.fields.includes('previewId');
            setError({ message: caught instanceof HttpError ? caught.message : 'Adding to qBittorrent failed.', expired });
        } finally {
            setBusy(null);
        }
    }

    if (step.kind === 'done') {
        return (
            <ImportResult
                result={step.result}
                onAgain={() => {
                    setUrl('');
                    setStep({ kind: 'url' });
                }}
                onClose={onClose}
            />
        );
    }

    if (step.kind === 'preview') {
        return (
            <PreviewStep
                preview={step.preview}
                selected={selected}
                onSelect={setSelected}
                busy={busy}
                retargeting={retargeting}
                error={error}
                onBack={() => {
                    setError(null);
                    setStep({ kind: 'url' });
                }}
                onRepreview={() => preview()}
                onRetarget={(seriesKey, target) => retarget(step.preview, seriesKey, target)}
                onConfirm={() => confirm(step.preview.previewId)}
            />
        );
    }

    return (
        <form onSubmit={preview} className="flex flex-col gap-3 p-4 sm:p-6">
            <label htmlFor="nyaa-url" className="text-sm font-medium">
                Nyaa RSS link
            </label>
            <div className="flex flex-col gap-2 sm:flex-row">
                <Input
                    id="nyaa-url"
                    type="url"
                    inputMode="url"
                    autoComplete="off"
                    autoFocus
                    placeholder="https://nyaa.si/?page=rss&q=…"
                    value={url}
                    onChange={(event) => setUrl(event.target.value)}
                    aria-invalid={error !== null}
                    aria-describedby={error ? 'nyaa-url-error' : 'nyaa-url-help'}
                />
                <Button type="submit" className="shrink-0 gap-1.5" disabled={!url.trim() || busy !== null}>
                    {busy === 'preview' && <Loader2 className="size-4 animate-spin" />}
                    {busy === 'preview' ? 'Previewing…' : 'Preview'}
                </Button>
            </div>
            {error && (
                <p id="nyaa-url-error" role="alert" className="text-sm text-destructive">
                    {error.message}
                </p>
            )}
            <p id="nyaa-url-help" className="text-sm text-muted-foreground">
                Search on nyaa.si (for example "[SubsPlease] show name 1080"), then copy the link of the RSS button above the results. Any release
                group works. You'll see exactly what would be added, and to which show, before anything is. No rule is created and nothing gets
                tracked.
            </p>
        </form>
    );
}

/* ------------------------------------------------------------------ preview */

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function itemLabel(item: NyaaPreviewItem): string {
    if (item.showName === null) {
        return 'Unparsed';
    }

    if (item.isBatch) {
        return item.batchFrom !== null && item.batchTo !== null ? `Eps ${pad(item.batchFrom)}–${pad(item.batchTo)}` : 'Batch';
    }

    return item.episode !== null ? `Ep ${item.episode}` : 'Special';
}

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

const BADGE = 'inline-flex items-center rounded-md border px-1.5 text-[11px] leading-5 font-medium whitespace-nowrap';
const MUTED_BADGE = 'border-transparent bg-secondary text-secondary-foreground';

/** The state as a short badge; `reason` (or a plain explanation) is its tooltip. */
function stateBadge(item: NyaaPreviewItem): { label: string; className: string; hint: string } {
    const hint = item.reason ?? 'Neither qBittorrent nor Torii has this release yet.';

    switch (item.state) {
        case 'unparsed':
            return { label: 'unparsed', className: 'border-dashed text-muted-foreground', hint };
        case 'in_qbit':
            return { label: 'in qBit', className: MUTED_BADGE, hint };
        case 'known_release':
            return { label: 'already have', className: MUTED_BADGE, hint };
        case 'have_episode':
            return { label: 'have episode', className: MUTED_BADGE, hint };
        case 'superseded':
            return { label: 'older version', className: 'text-muted-foreground', hint };
        case 'duplicate_episode':
            return { label: 'duplicate', className: 'text-muted-foreground', hint };
        default:
            if (item.remake) {
                return { label: 'remake', className: 'border-amber-500/50 text-amber-700 dark:text-amber-300', hint };
            }

            if (item.reason) {
                return { label: 'in a batch', className: 'text-muted-foreground', hint };
            }

            return { label: 'new', className: 'border-green-600/40 text-green-700 dark:text-green-400', hint };
    }
}

function targetName(target: NyaaTarget): string {
    return target.kind === 'existing' ? target.showName : target.name;
}

/** What confirming the current selection would do: releases per show, and which shows it creates. */
function selectionSummary(preview: NyaaPreview, selected: Set<string>) {
    const shows = new Map<string, { name: string; showId: number | null }>();
    let releases = 0;

    for (const series of preview.series) {
        const count = series.itemKeys.filter((key) => selected.has(key)).length;

        if (count === 0) {
            continue;
        }

        releases += count;
        const { target } = series;
        shows.set(target.kind === 'existing' ? `show:${target.showId}` : `new:${target.name.toLowerCase()}`, {
            name: targetName(target),
            showId: target.kind === 'existing' ? target.showId : null,
        });
    }

    const list = [...shows.values()];

    return { releases, shows: list, creates: list.filter((show) => show.showId === null).length };
}

interface PreviewStepProps {
    preview: NyaaPreview;
    selected: Set<string>;
    onSelect: (next: Set<string>) => void;
    busy: 'preview' | 'confirm' | null;
    retargeting: string | null;
    error: { message: string; expired: boolean } | null;
    onBack: () => void;
    onRepreview: () => void;
    onRetarget: (seriesKey: string, target: TargetChoice) => void;
    onConfirm: () => void;
}

function PreviewStep({ preview, selected, onSelect, busy, retargeting, error, onBack, onRepreview, onRetarget, onConfirm }: PreviewStepProps) {
    const { items, series } = preview;
    const byKey = new Map(items.map((item) => [item.key, item]));
    const selectable = selectableKeys(preview);
    const unparsed = items.filter((item) => !selectable.has(item.key));
    const suggested = suggestedKeys(preview);
    const isSuggested = suggested.length === selected.size && suggested.every((key) => selected.has(key));
    const summary = selectionSummary(preview, selected);
    const locked = busy !== null || retargeting !== null;

    function setMany(keys: string[], on: boolean) {
        const next = new Set(selected);

        for (const key of keys) {
            if (on) {
                next.add(key);
            } else {
                next.delete(key);
            }
        }

        onSelect(next);
    }

    return (
        <>
            <div className="flex flex-col gap-2 border-b px-3 py-2.5 sm:gap-3 sm:p-4 sm:px-6">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-0.5">
                    <p className="min-w-0 text-sm">
                        <SummaryText summary={summary} />
                    </p>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {selected.size} of {selectable.size} selected
                    </p>
                </div>
                <p className="hidden truncate text-xs text-muted-foreground sm:block" title={preview.feedTitle}>
                    {preview.feedTitle}
                </p>

                {(preview.truncated || preview.warnings.length > 0) && (
                    <ul className="flex flex-col gap-1.5">
                        {preview.truncated && (
                            <Warning>Nyaa returned a full page; there may be more episodes. Narrow the search to see them.</Warning>
                        )}
                        {preview.warnings.map((warning) => (
                            <Warning key={warning}>{warning}</Warning>
                        ))}
                    </ul>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => onSelect(new Set(selectable))}
                        disabled={locked || selected.size === selectable.size}
                    >
                        Select all
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => onSelect(new Set())} disabled={locked || selected.size === 0}>
                        Select none
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => onSelect(new Set(suggested))} disabled={locked || isSuggested}>
                        Suggested
                    </Button>
                </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
                {items.length === 0 && <p className="p-6 text-center text-sm text-muted-foreground">This feed has no items.</p>}
                {series.map((entry) => (
                    <SeriesSection
                        key={entry.key}
                        series={entry}
                        items={entry.itemKeys.map((key) => byKey.get(key)).filter((item): item is NyaaPreviewItem => item !== undefined)}
                        selected={selected}
                        onSetMany={setMany}
                        disabled={busy !== null || retargeting === entry.key}
                        retargeting={retargeting === entry.key}
                        onRetarget={(target) => onRetarget(entry.key, target)}
                    />
                ))}
                {unparsed.length > 0 && <UnparsedSection items={unparsed} />}
            </div>

            <div className="flex flex-col gap-3 border-t p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] sm:p-4 sm:px-6">
                {error && (
                    <div role="alert" className="flex flex-wrap items-center gap-2 text-sm text-destructive">
                        <span className="min-w-0 flex-1">{error.message}</span>
                        {error.expired && (
                            <Button size="sm" variant="outline" onClick={onRepreview} disabled={busy !== null}>
                                Preview again
                            </Button>
                        )}
                    </div>
                )}
                <div className="flex items-center justify-between gap-2">
                    <Button variant="ghost" className="shrink-0 px-2 sm:px-4" onClick={onBack} disabled={locked}>
                        Change link
                    </Button>
                    <Button onClick={onConfirm} disabled={selected.size === 0 || locked} className="gap-1.5">
                        {busy === 'confirm' && <Loader2 className="size-4 animate-spin" />}
                        {busy === 'confirm' ? 'Adding…' : `Add ${selected.size} to qBittorrent`}
                    </Button>
                </div>
            </div>
        </>
    );
}

function SummaryText({ summary }: { summary: ReturnType<typeof selectionSummary> }) {
    const { releases, shows, creates } = summary;

    if (releases === 0) {
        return <span className="text-muted-foreground">Nothing selected.</span>;
    }

    if (shows.length === 1) {
        const [show] = shows;

        return show.showId === null ? (
            <>
                Adds {plural(releases, 'release')} to <span className="font-medium">{show.name}</span>,{' '}
                <span className="text-muted-foreground">a new show (not tracked)</span>
            </>
        ) : (
            <>
                Adds {plural(releases, 'release')} to{' '}
                <Link href={`/shows/${show.showId}`} className="font-medium hover:underline">
                    {show.name}
                </Link>
            </>
        );
    }

    return (
        <>
            Adds {plural(releases, 'release')} to {shows.length} shows{creates > 0 && <>, creates {creates}</>}
        </>
    );
}

function Warning({ children }: { children: ReactNode }) {
    return (
        <li className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 px-2.5 py-1.5 text-xs text-amber-800 sm:px-3 sm:py-2 sm:text-sm dark:text-amber-200">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>{children}</span>
        </li>
    );
}

/* ------------------------------------------------------------------- series */

/** "· season 2", unless the name already carries it (a SubsPlease name such as "Black Clover S2"). */
function seasonSuffix(series: NyaaSeries): string | null {
    if (series.season === null) {
        return null;
    }

    const nameHasIt = series.parsedName === series.suggestedName && series.season > 1;

    return nameHasIt ? null : `season ${series.season}`;
}

interface SeriesSectionProps {
    series: NyaaSeries;
    items: NyaaPreviewItem[];
    selected: Set<string>;
    onSetMany: (keys: string[], on: boolean) => void;
    /** While its target is being changed (or anything else runs): no ticking, no picking. */
    disabled: boolean;
    retargeting: boolean;
    onRetarget: (target: TargetChoice) => void;
}

function SeriesSection({ series, items, selected, onSetMany, disabled, retargeting, onRetarget }: SeriesSectionProps) {
    const headingId = useId();
    const keys = items.map((item) => item.key);
    const count = keys.filter((key) => selected.has(key)).length;
    const all = count === keys.length && keys.length > 0;
    const season = seasonSuffix(series);
    const severalGroups = series.groups.length > 1;

    return (
        <section aria-labelledby={headingId} aria-busy={retargeting} className="border-b last:border-b-0">
            {/* A fieldset disables every control inside at once (checkboxes, picker, links stay usable). */}
            <fieldset disabled={disabled} className={cn('m-0 min-w-0 border-0 p-0', retargeting && 'opacity-60')}>
                {/* One line: name · season, group badges, count. Sticky only from sm up: on phones it would eat the screen. */}
                <div className="flex items-start gap-3 border-b bg-muted px-3 py-2 sm:sticky sm:top-0 sm:z-10 sm:px-6">
                    <Checkbox
                        checked={all ? true : count > 0 ? 'indeterminate' : false}
                        onCheckedChange={() => onSetMany(keys, !all)}
                        aria-label={all ? `Select none of ${series.suggestedName}` : `Select all of ${series.suggestedName}`}
                        className={cn('mt-px', TOUCH_TARGET_SM)}
                    />
                    <div className="flex min-w-0 flex-1 flex-wrap items-center gap-x-2 gap-y-0.5">
                        <h3 id={headingId} className="min-w-0 text-sm leading-5 font-semibold break-words">
                            {series.parsedName}
                            {season && <span className="font-normal text-muted-foreground"> · {season}</span>}
                        </h3>
                        {series.groups.map((group) => (
                            <span key={group} className={cn(BADGE, 'bg-background text-muted-foreground')}>
                                {group}
                            </span>
                        ))}
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {plural(keys.length, 'item')}
                            {count !== keys.length && ` · ${count} selected`}
                        </span>
                    </div>
                </div>

                <TargetPicker series={series} busy={retargeting} onChoose={onRetarget} />

                <ol className="divide-y" aria-label={`Releases of ${series.suggestedName}`}>
                    {items.map((item) => (
                        <PreviewRow
                            key={item.key}
                            item={item}
                            checked={selected.has(item.key)}
                            onToggle={(on) => onSetMany([item.key], on)}
                            showGroup={severalGroups}
                        />
                    ))}
                </ol>
            </fieldset>
        </section>
    );
}

/**
 * A short muted marker beside the picker when the target can't be tracked; the
 * backend's reason is on hover (pointer) or tap (touch). Importing is still fine.
 */
function TrackBlockedNote({ reason }: { reason: string | null }) {
    return (
        <Hint
            trigger={
                <button type="button" className="inline-flex cursor-help items-center gap-1 text-xs text-muted-foreground hover:text-foreground">
                    <Info className="size-3.5 shrink-0" aria-hidden />
                    Can&apos;t be tracked
                </button>
            }
        >
            {reason ?? "This show can't be tracked."}
        </Hint>
    );
}

function TargetPicker({ series, busy, onChoose }: { series: NyaaSeries; busy: boolean; onChoose: (target: TargetChoice) => void }) {
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const { target } = series;

    function choose(choice: TargetChoice) {
        setOpen(false);
        onChoose(choice);
    }

    return (
        <div className="flex flex-col gap-2 border-b px-3 py-2 sm:px-6">
            <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-sm">
                <span className="text-muted-foreground">Goes to</span>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="h-auto min-h-8 max-w-full justify-between gap-1.5 py-1 text-left whitespace-normal"
                    aria-expanded={open}
                    aria-controls={panelId}
                    onClick={() => setOpen((value) => !value)}
                >
                    <span className="min-w-0 break-words">
                        {target.kind === 'new' ? (
                            <>
                                <span className="font-normal text-muted-foreground">New show: </span>
                                {target.name}
                            </>
                        ) : (
                            target.showName
                        )}
                    </span>
                    {busy ? (
                        <Loader2 className="size-4 shrink-0 animate-spin" />
                    ) : (
                        <ChevronDown className={cn('size-4 shrink-0 transition-transform', open && 'rotate-180')} />
                    )}
                </Button>
                {target.kind === 'existing' && (
                    <Link
                        href={`/shows/${target.showId}`}
                        className="inline-flex items-center gap-0.5 text-xs text-muted-foreground hover:text-foreground hover:underline"
                    >
                        open show
                        <ArrowRight className="size-3" aria-hidden />
                    </Link>
                )}
                {!target.trackable && <TrackBlockedNote reason={target.trackBlockedReason} />}
            </div>

            {open && <TargetPanel id={panelId} series={series} onChoose={choose} />}
        </div>
    );
}

type SearchState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'error'; message: string }
    | { status: 'loaded'; shows: NyaaShowSearch['shows'] };

const SEARCH_DEBOUNCE_MS = 250;

const OPTION =
    'flex w-full flex-col items-start gap-0.5 rounded-md px-2.5 py-1.5 text-left text-sm hover:bg-accent focus-visible:bg-accent focus-visible:outline-none disabled:cursor-default disabled:hover:bg-transparent [@media(hover:none)]:min-h-11 [@media(hover:none)]:justify-center';

/** Candidates first, then a search over every show, then "Create a new show…". Inline, so it scrolls with the list. */
function TargetPanel({ id, series, onChoose }: { id: string; series: NyaaSeries; onChoose: (target: TargetChoice) => void }) {
    const { target, candidates } = series;
    const [query, setQuery] = useState('');
    const [search, setSearch] = useState<SearchState>({ status: 'idle' });
    const [creating, setCreating] = useState(false);
    const [newName, setNewName] = useState(series.suggestedName);
    const controllerRef = useRef<AbortController | null>(null);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    const currentId = target.kind === 'existing' ? target.showId : null;

    useEffect(
        () => () => {
            controllerRef.current?.abort();
            clearTimeout(debounceRef.current);
        },
        [],
    );

    function runSearch(q: string) {
        // A newer search always wins: the previous request is aborted.
        controllerRef.current?.abort();

        if (q.trim() === '') {
            setSearch({ status: 'idle' });
            return;
        }

        const controller = new AbortController();
        controllerRef.current = controller;
        setSearch({ status: 'loading' });

        getJson<NyaaShowSearch>(`/import/nyaa/shows?q=${encodeURIComponent(q.trim())}`, controller.signal)
            .then((result) => setSearch({ status: 'loaded', shows: result.shows }))
            .catch((caught: unknown) => {
                if (!controller.signal.aborted) {
                    setSearch({ status: 'error', message: caught instanceof HttpError ? caught.message : 'The search failed.' });
                }
            });
    }

    function handleQuery(value: string) {
        setQuery(value);
        clearTimeout(debounceRef.current);
        debounceRef.current = setTimeout(() => runSearch(value), SEARCH_DEBOUNCE_MS);
    }

    function submitNew(event: FormEvent) {
        event.preventDefault();
        const name = newName.trim();

        if (name !== '') {
            onChoose({ newName: name });
        }
    }

    return (
        <div id={id} className="flex flex-col gap-3 rounded-md border bg-background p-2.5 shadow-xs">
            {candidates.length > 0 && (
                <div className="flex flex-col gap-0.5">
                    <p className="px-2.5 text-xs font-medium text-muted-foreground">Suggested</p>
                    <ul className="flex flex-col">
                        {candidates.map((candidate) => (
                            <li key={candidate.showId}>
                                <button
                                    type="button"
                                    className={OPTION}
                                    disabled={candidate.showId === currentId}
                                    aria-current={candidate.showId === currentId ? 'true' : undefined}
                                    onClick={() => onChoose({ showId: candidate.showId })}
                                >
                                    <span className="flex w-full items-center gap-1.5 font-medium">
                                        <span className="min-w-0 break-words">{candidate.showName}</span>
                                        {candidate.showId === currentId && <Check className="size-4 shrink-0 text-primary" aria-label="current" />}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {candidate.why}
                                        {!candidate.trackable && ' · not trackable'}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div className="flex flex-col gap-1">
                <div className="relative">
                    <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" aria-hidden />
                    <Input
                        value={query}
                        onChange={(event) => handleQuery(event.target.value)}
                        placeholder="Search your shows…"
                        aria-label="Search your shows"
                        className="px-9"
                        autoFocus={candidates.length === 0}
                    />
                    {search.status === 'loading' && (
                        <Loader2 className="absolute top-1/2 right-3 size-4 -translate-y-1/2 animate-spin text-muted-foreground" aria-hidden />
                    )}
                </div>
                {search.status === 'error' && <p className="px-2.5 text-xs text-destructive">{search.message}</p>}
                {search.status === 'loaded' &&
                    (search.shows.length === 0 ? (
                        <p className="px-2.5 py-1 text-xs text-muted-foreground">No show matches "{query.trim()}".</p>
                    ) : (
                        <ul className="flex max-h-64 flex-col overflow-y-auto">
                            {search.shows.map((show) => (
                                <li key={show.id}>
                                    <button
                                        type="button"
                                        className={OPTION}
                                        disabled={show.id === currentId}
                                        aria-current={show.id === currentId ? 'true' : undefined}
                                        onClick={() => onChoose({ showId: show.id })}
                                    >
                                        <span className="flex w-full items-center gap-1.5">
                                            <span className="min-w-0 break-words">{show.name}</span>
                                            {show.id === currentId && <Check className="size-4 shrink-0 text-primary" aria-label="current" />}
                                        </span>
                                        <span className="text-xs text-muted-foreground">
                                            {[
                                                show.latestEpisode !== null && `latest ep ${show.latestEpisode}`,
                                                show.isTracked && 'tracked',
                                                !show.trackable && 'not trackable',
                                            ]
                                                .filter(Boolean)
                                                .join(' · ') || 'no episodes yet'}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    ))}
            </div>

            {creating ? (
                <form onSubmit={submitNew} className="flex flex-col gap-1.5 border-t pt-3">
                    <label htmlFor={`${id}-new`} className="px-0.5 text-xs font-medium text-muted-foreground">
                        Name of the new show
                    </label>
                    <div className="flex flex-col gap-2 sm:flex-row">
                        <Input
                            id={`${id}-new`}
                            value={newName}
                            onChange={(event) => setNewName(event.target.value)}
                            maxLength={255}
                            autoFocus
                            autoComplete="off"
                        />
                        <div className="flex gap-2">
                            <Button type="submit" className="flex-1 sm:flex-none" disabled={newName.trim() === ''}>
                                Use this name
                            </Button>
                            <Button type="button" variant="ghost" onClick={() => setCreating(false)}>
                                Cancel
                            </Button>
                        </div>
                    </div>
                    <p className="px-0.5 text-xs text-muted-foreground">
                        Created untracked when you add the releases. A show with exactly this name is used instead.
                    </p>
                </form>
            ) : (
                <div className="border-t pt-2">
                    <button
                        type="button"
                        className={cn(OPTION, 'flex-row items-center gap-1.5 [@media(hover:none)]:justify-start')}
                        onClick={() => setCreating(true)}
                    >
                        <Plus className="size-4 shrink-0" aria-hidden />
                        Create a new show…
                    </button>
                </div>
            )}
        </div>
    );
}

/* --------------------------------------------------------------------- rows */

function PreviewRow({
    item,
    checked,
    onToggle,
    showGroup,
}: {
    item: NyaaPreviewItem;
    checked: boolean;
    onToggle: (on: boolean) => void;
    showGroup: boolean;
}) {
    const badge = stateBadge(item);
    const id = `nyaa-item-${item.key}`;

    return (
        <li className={cn('flex items-start gap-3 px-3 py-2 sm:px-6 sm:py-2.5', !checked && 'bg-muted/30')}>
            <Checkbox id={id} checked={checked} onCheckedChange={(value) => onToggle(value === true)} className={cn('mt-0.5', TOUCH_TARGET_SM)} />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    {showGroup && item.group && <span className={cn(BADGE, 'order-1 max-w-36 truncate text-muted-foreground')}>{item.group}</span>}
                    <label htmlFor={id} className="order-1 cursor-pointer text-sm font-medium tabular-nums">
                        {item.season !== null && <span className="text-muted-foreground">S{pad(item.season)} </span>}
                        {itemLabel(item)}
                    </label>
                    {item.version !== null && item.version > 1 && <span className={cn(BADGE, 'order-2 text-muted-foreground')}>v{item.version}</span>}
                    {/* Phone: the state badge follows the label and the details wrap below; wider: details inline, badge on the right. */}
                    <span className="order-4 flex basis-full flex-wrap items-center gap-x-2 sm:order-3 sm:basis-auto">
                        <ItemFacts item={item} />
                    </span>
                    <span className="order-3 sm:order-4 sm:ml-auto">
                        <Hint
                            trigger={
                                <button type="button" className={cn(BADGE, 'cursor-help', badge.className)}>
                                    {badge.label}
                                </button>
                            }
                        >
                            {badge.hint}
                        </Hint>
                    </span>
                </div>
                <RawTitle item={item} />
            </div>
        </li>
    );
}

function ItemFacts({ item }: { item: NyaaPreviewItem }) {
    return (
        <>
            <span className="text-xs text-muted-foreground tabular-nums">
                {[item.resolution, item.size].filter(Boolean).join(' · ')}
                {item.seeders !== null && (
                    <>
                        {(item.resolution || item.size) && ' · '}
                        <span className={cn(item.seeders === 0 && 'text-amber-700 dark:text-amber-300')}>
                            {item.seeders === 0 ? 'no seeders' : `${item.seeders} seeders`}
                        </span>
                    </>
                )}
            </span>
            {item.trusted && (
                <span className="inline-flex items-center gap-0.5 text-xs text-green-700 dark:text-green-400" title="Trusted uploader on Nyaa">
                    <ShieldCheck className="size-3.5" aria-hidden />
                    trusted
                </span>
            )}
        </>
    );
}

/**
 * The title as Nyaa wrote it, so the parse can be checked: one truncated line
 * (full text on hover), the whole title on click or tap.
 */
function RawTitle({ item }: { item: NyaaPreviewItem }) {
    const [expanded, setExpanded] = useState(false);

    return (
        <div className="flex min-w-0 items-start gap-1.5 text-xs text-muted-foreground">
            <button
                type="button"
                className={cn('flex min-w-0 flex-1 items-start gap-1 text-left hover:text-foreground', expanded && 'text-foreground')}
                aria-expanded={expanded}
                title={expanded ? undefined : item.title}
                onClick={() => setExpanded((value) => !value)}
            >
                <ChevronRight className={cn('mt-px size-3.5 shrink-0 transition-transform', expanded && 'rotate-90')} aria-hidden />
                <span className={cn('min-w-0', expanded ? '[overflow-wrap:anywhere] whitespace-normal' : 'truncate')}>{item.title}</span>
            </button>
            <a
                href={item.viewUrl}
                target="_blank"
                rel="noopener noreferrer"
                className={cn('shrink-0 hover:text-foreground', TOUCH_TARGET_SM)}
                aria-label={`Open ${item.title} on Nyaa`}
            >
                <ExternalLink className="size-3.5" />
            </a>
        </div>
    );
}

/** Titles the parser couldn't read: listed so nothing silently disappears, but not selectable (no show to go to). */
function UnparsedSection({ items }: { items: NyaaPreviewItem[] }) {
    return (
        <Collapsible className="border-t">
            <CollapsibleTrigger className="group flex w-full items-center gap-2 px-3 py-3 text-left text-sm font-medium hover:bg-muted/50 sm:px-6">
                <ChevronRight className="size-4 shrink-0 transition-transform group-data-[state=open]:rotate-90" aria-hidden />
                {items.length === 1 ? "1 title Torii couldn't read" : `${items.length} titles Torii couldn't read`}
            </CollapsibleTrigger>
            <CollapsibleContent>
                <ul className="divide-y border-t">
                    {items.map((item) => (
                        <li key={item.key} className="flex items-start gap-2 bg-muted/30 px-3 py-2 sm:px-6 sm:py-2.5">
                            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="text-sm [overflow-wrap:anywhere]">{item.title}</span>
                                <span className="text-xs text-muted-foreground">
                                    {item.reason ?? "Torii couldn't read a show and episode from this title."}
                                    {item.size && ` · ${item.size}`}
                                </span>
                            </div>
                            <a
                                href={item.viewUrl}
                                target="_blank"
                                rel="noopener noreferrer"
                                className={cn('mt-0.5 shrink-0 text-muted-foreground hover:text-foreground', TOUCH_TARGET_SM)}
                                aria-label={`Open ${item.title} on Nyaa`}
                            >
                                <ExternalLink className="size-3.5" />
                            </a>
                        </li>
                    ))}
                </ul>
            </CollapsibleContent>
        </Collapsible>
    );
}

/* ------------------------------------------------------------------- result */

function ImportResult({ result, onAgain, onClose }: { result: NyaaConfirmResult; onAgain: () => void; onClose: () => void }) {
    const { queued, skipped, errors, shows } = result;

    return (
        <div className="flex min-h-0 flex-1 flex-col">
            <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 sm:p-6">
                <div className="flex flex-col gap-1">
                    <p className="text-base font-medium">
                        {queued > 0 ? `Sent ${queued} release${queued === 1 ? '' : 's'} to qBittorrent.` : 'Nothing was sent to qBittorrent.'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {[skipped.length > 0 && `${skipped.length} skipped`, errors.length > 0 && `${errors.length} failed`]
                            .filter(Boolean)
                            .join(', ') ||
                            'On the show page they appear as queued (or "in qBit" if qBittorrent already had them), then as downloaded once it finishes.'}
                    </p>
                </div>

                {shows.length > 0 && (
                    <ul className="flex flex-col gap-1 text-sm">
                        {shows.map((show) => (
                            <li key={show.id}>
                                <Link
                                    href={`/shows/${show.id}`}
                                    className="inline-flex items-center gap-1 font-medium hover:underline"
                                    onClick={onClose}
                                >
                                    {show.name}
                                    <ArrowRight className="size-3.5" aria-hidden />
                                </Link>
                                {show.created && <span className="text-muted-foreground"> · new show, not tracked</span>}
                            </li>
                        ))}
                    </ul>
                )}

                {skipped.length > 0 && (
                    <Outcome title="Skipped" entries={skipped.map((entry) => ({ key: entry.key, title: entry.title, text: entry.reason }))} />
                )}
                {errors.length > 0 && (
                    <Outcome
                        title="Failed"
                        tone="error"
                        entries={errors.map((entry) => ({ key: entry.key, title: entry.title, text: entry.message }))}
                    />
                )}
            </div>
            <div className="flex flex-col-reverse gap-2 border-t p-4 sm:flex-row sm:justify-end sm:px-6">
                <Button variant="outline" onClick={onAgain}>
                    Import another
                </Button>
                <Button onClick={onClose}>Close</Button>
            </div>
        </div>
    );
}

function Outcome({ title, entries, tone }: { title: string; entries: { key: string; title: string; text: string }[]; tone?: 'error' }) {
    return (
        <section className="flex flex-col gap-1.5">
            <h3 className={cn('text-sm font-medium', tone === 'error' && 'text-destructive')}>{title}</h3>
            <ul className="flex flex-col divide-y rounded-md border text-sm">
                {entries.map((entry) => (
                    <li key={entry.key} className="flex flex-col gap-0.5 px-3 py-2">
                        <span className="truncate" title={entry.title}>
                            {entry.title}
                        </span>
                        <span className={cn('text-xs', tone === 'error' ? 'text-destructive' : 'text-muted-foreground')}>{entry.text}</span>
                    </li>
                ))}
            </ul>
        </section>
    );
}
