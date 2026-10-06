import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { HttpError, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import { type NyaaConfirmResult, type NyaaPreview, type NyaaPreviewItem } from '@/types/subtracker';
import { Link, router } from '@inertiajs/react';
import { ArrowRight, ExternalLink, Import, Loader2, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
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
 * Dialog on wider screens, full-height sheet on phones. The flow lives inside the
 * content, which unmounts on close: closing always discards the preview.
 */
export function NyaaImportDialog({ open, onOpenChange }: { open: boolean; onOpenChange: (open: boolean) => void }) {
    const mobile = useIsMobile();
    const close = () => onOpenChange(false);

    if (mobile) {
        return (
            <Sheet open={open} onOpenChange={onOpenChange}>
                <SheetContent side="bottom" className="flex h-[100dvh] max-h-[100dvh] flex-col gap-0 p-0 pt-[env(safe-area-inset-top)]">
                    <SheetHeader className="border-b p-4 pr-12 text-left">
                        <SheetTitle>Import from Nyaa</SheetTitle>
                        <SheetDescription>{DESCRIPTION}</SheetDescription>
                    </SheetHeader>
                    <ImportFlow onClose={close} />
                </SheetContent>
            </Sheet>
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="flex max-h-[90vh] flex-col gap-0 p-0 sm:max-w-3xl">
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

function ImportFlow({ onClose }: { onClose: () => void }) {
    const [url, setUrl] = useState('');
    const [step, setStep] = useState<Step>({ kind: 'url' });
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [busy, setBusy] = useState<'preview' | 'confirm' | null>(null);
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
            setSelected(new Set(result.items.filter((item) => item.selected).map((item) => item.key)));
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
                error={error}
                onBack={() => {
                    setError(null);
                    setStep({ kind: 'url' });
                }}
                onRepreview={() => preview()}
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
                Search on nyaa.si (for example "[SubsPlease] show name 1080"), then copy the link of the RSS button above the results. You'll see
                exactly what would be added before anything is.
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

const BADGE = 'inline-flex items-center rounded-md border px-1.5 text-[11px] leading-5 font-medium whitespace-nowrap';

/** The state as a short badge; `reason` (or a plain explanation) is its tooltip. */
function stateBadge(item: NyaaPreviewItem): { label: string; className: string; hint: string } {
    const hint = item.reason ?? 'Neither qBittorrent nor Torii has this release yet.';

    switch (item.state) {
        case 'unparsed':
            return { label: 'unparsed', className: 'border-dashed text-muted-foreground', hint };
        case 'in_qbit':
            return { label: 'in qBit', className: 'border-transparent bg-secondary text-secondary-foreground', hint };
        case 'known_release':
            return { label: 'already have', className: 'border-transparent bg-secondary text-secondary-foreground', hint };
        case 'superseded':
            return { label: 'older version', className: 'text-muted-foreground', hint };
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

interface PreviewStepProps {
    preview: NyaaPreview;
    selected: Set<string>;
    onSelect: (next: Set<string>) => void;
    busy: 'preview' | 'confirm' | null;
    error: { message: string; expired: boolean } | null;
    onBack: () => void;
    onRepreview: () => void;
    onConfirm: () => void;
}

function PreviewStep({ preview, selected, onSelect, busy, error, onBack, onRepreview, onConfirm }: PreviewStepProps) {
    const { items, show } = preview;
    const suggested = items.filter((item) => item.selected).map((item) => item.key);
    const isSuggested = suggested.length === selected.size && suggested.every((key) => selected.has(key));
    const severalShows = show === null && items.some((item) => item.showName !== null);

    function toggle(key: string, on: boolean) {
        const next = new Set(selected);

        if (on) {
            next.add(key);
        } else {
            next.delete(key);
        }

        onSelect(next);
    }

    return (
        <>
            <div className="flex flex-col gap-3 border-b p-4 sm:px-6">
                <div className="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                    <p className="min-w-0 text-sm">
                        {show ? (
                            show.willCreate ? (
                                <>
                                    Creates <span className="font-medium">{show.name}</span>{' '}
                                    <span className="text-muted-foreground">(not tracked)</span>
                                </>
                            ) : (
                                <>
                                    Adds to{' '}
                                    <Link href={`/shows/${show.existingShowId}`} className="font-medium hover:underline">
                                        {show.name}
                                    </Link>
                                </>
                            )
                        ) : severalShows ? (
                            'Adds each item to the show its title names.'
                        ) : (
                            'None of these titles name a SubsPlease show.'
                        )}
                    </p>
                    <p className="text-sm text-muted-foreground tabular-nums">
                        {selected.size} of {items.length} selected
                    </p>
                </div>
                <p className="truncate text-xs text-muted-foreground" title={preview.feedTitle}>
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
                        onClick={() => onSelect(new Set(items.map((item) => item.key)))}
                        disabled={selected.size === items.length}
                    >
                        Select all
                    </Button>
                    <Button size="sm" variant="outline" onClick={() => onSelect(new Set())} disabled={selected.size === 0}>
                        Select none
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => onSelect(new Set(suggested))} disabled={isSuggested}>
                        Suggested
                    </Button>
                </div>
            </div>

            <ol className="min-h-0 flex-1 divide-y overflow-y-auto" aria-label="Releases in this feed">
                {items.length === 0 && <li className="p-6 text-center text-sm text-muted-foreground">This feed has no items.</li>}
                {items.map((item) => (
                    <PreviewRow
                        key={item.key}
                        item={item}
                        checked={selected.has(item.key)}
                        onToggle={(on) => toggle(item.key, on)}
                        showName={severalShows}
                    />
                ))}
            </ol>

            <div className="flex flex-col gap-3 border-t p-4 sm:px-6">
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
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <Button variant="ghost" onClick={onBack} disabled={busy !== null}>
                        Change link
                    </Button>
                    <Button onClick={onConfirm} disabled={selected.size === 0 || busy !== null} className="gap-1.5">
                        {busy === 'confirm' && <Loader2 className="size-4 animate-spin" />}
                        {busy === 'confirm' ? 'Adding…' : `Add ${selected.size} to qBittorrent`}
                    </Button>
                </div>
            </div>
        </>
    );
}

function Warning({ children }: { children: ReactNode }) {
    return (
        <li className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/5 px-3 py-2 text-sm text-amber-800 dark:text-amber-200">
            <TriangleAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
            <span>{children}</span>
        </li>
    );
}

function PreviewRow({
    item,
    checked,
    onToggle,
    showName,
}: {
    item: NyaaPreviewItem;
    checked: boolean;
    onToggle: (on: boolean) => void;
    showName: boolean;
}) {
    const badge = stateBadge(item);
    const id = `nyaa-item-${item.key}`;

    return (
        <li className={cn('flex items-start gap-3 px-4 py-2.5 sm:px-6', !checked && 'bg-muted/30')}>
            <Checkbox id={id} checked={checked} onCheckedChange={(value) => onToggle(value === true)} className="mt-0.5" />
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <label htmlFor={id} className="order-1 cursor-pointer text-sm font-medium tabular-nums">
                        {itemLabel(item)}
                    </label>
                    {item.version !== null && item.version > 1 && <span className={cn(BADGE, 'order-2 text-muted-foreground')}>v{item.version}</span>}
                    {/* Phone: the state badge follows the label and the details wrap below; wider: details inline, badge on the right. */}
                    <span className="order-4 flex basis-full flex-wrap items-center gap-x-2 sm:order-3 sm:basis-auto">
                        <span className="text-xs text-muted-foreground tabular-nums">
                            {[item.resolution, item.size].filter(Boolean).join(' · ')}
                            {item.seeders !== null && (
                                <>
                                    {' · '}
                                    <span className={cn(item.seeders === 0 && 'text-amber-700 dark:text-amber-300')}>
                                        {item.seeders === 0 ? 'no seeders' : `${item.seeders} seeders`}
                                    </span>
                                </>
                            )}
                        </span>
                        {item.trusted && (
                            <span
                                className="inline-flex items-center gap-0.5 text-xs text-green-700 dark:text-green-400"
                                title="Trusted uploader on Nyaa"
                            >
                                <ShieldCheck className="size-3.5" aria-hidden />
                                trusted
                            </span>
                        )}
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
                <div className="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground">
                    {showName && item.showName && <span className="shrink-0 font-medium text-foreground">{item.showName}</span>}
                    <span className="truncate" title={item.title}>
                        {item.title}
                    </span>
                    <a
                        href={item.viewUrl}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="shrink-0 hover:text-foreground"
                        aria-label={`Open ${item.title} on Nyaa`}
                    >
                        <ExternalLink className="size-3.5" />
                    </a>
                </div>
            </div>
        </li>
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
