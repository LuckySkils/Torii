import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatLag } from '@/lib/dates';
import { type SharedData } from '@/types';
import { type BootstrapProgress, type BootstrapTaskState, type Health } from '@/types/subtracker';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, Circle, Loader2, X } from 'lucide-react';
import { useState } from 'react';
import { RelativeTime } from './relative-time';
import { TapInfo } from './tap-info';

interface HealthStripProps {
    health: Health;
}

function HealthBadge({ ok, label, tooltip }: { ok: boolean; label: string; tooltip: string }) {
    return (
        <TapInfo
            trigger={
                <Badge
                    variant={ok ? 'default' : 'destructive'}
                    className={`cursor-pointer justify-center py-1 ${ok ? 'border-transparent bg-green-600 text-white hover:bg-green-600/90' : ''}`}
                >
                    {label}
                </Badge>
            }
        >
            {tooltip}
        </TapInfo>
    );
}

const TASK_ICONS: Record<BootstrapTaskState, { icon: typeof Check; className: string; label: string }> = {
    done: { icon: Check, className: 'text-green-600 dark:text-green-400', label: 'done' },
    running: { icon: Loader2, className: 'animate-spin text-sky-600 dark:text-sky-400', label: 'running' },
    pending: { icon: Circle, className: 'text-muted-foreground', label: 'waiting' },
    failed: { icon: X, className: 'text-destructive', label: 'failed' },
};

/** "Initial sync in progress (2 of 5)", with the one-time tasks and their state in a tap-info. Gone once all are done. */
function BootstrapLine({ progress }: { progress: BootstrapProgress }) {
    const failed = progress.tasks.filter((task) => task.state === 'failed').length;

    return (
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
            <TapInfo
                trigger={
                    <button type="button" className="inline-flex cursor-pointer items-center gap-2 bg-transparent p-0 text-left">
                        <Loader2 className="size-3.5 animate-spin" aria-hidden />
                        <span className="underline decoration-dotted underline-offset-2">
                            Initial sync in progress ({progress.completed} of {progress.total})
                        </span>
                        <span className="h-1 w-16 overflow-hidden rounded-full bg-muted" aria-hidden>
                            <span className="block h-full rounded-full bg-sky-500/70" style={{ width: `${(progress.completed / Math.max(progress.total, 1)) * 100}%` }} />
                        </span>
                    </button>
                }
            >
                <p className="mb-2 text-xs text-muted-foreground">One-time tasks that fill in data after a fresh install. Nothing to do; they run in the background.</p>
                <ul className="flex flex-col gap-1.5">
                    {progress.tasks.map((task) => {
                        const { icon: Icon, className, label } = TASK_ICONS[task.state];

                        return (
                            <li key={task.key} className="flex items-start gap-2">
                                <Icon className={`mt-0.5 size-3.5 shrink-0 ${className}`} aria-label={label} />
                                <span className="flex min-w-0 flex-col">
                                    <span className={task.state === 'done' ? 'text-muted-foreground' : undefined}>{task.label}</span>
                                    {task.error && <span className="text-xs break-words text-destructive">{task.error} · retried on the next start</span>}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            </TapInfo>
            {failed > 0 && <span className="text-destructive">· {failed} failed</span>}
        </div>
    );
}

export function HealthStrip({ health }: HealthStripProps) {
    const { notifications, pendingLinkSuggestions } = usePage<SharedData>().props;
    const [polling, setPolling] = useState(false);
    const [reconciling, setReconciling] = useState(false);
    const [fetchingImages, setFetchingImages] = useState(false);
    const [sendingTest, setSendingTest] = useState(false);

    function forcePoll() {
        setPolling(true);
        router.post(
            '/feed/poll',
            {},
            {
                preserveScroll: true,
                onFinish: () => setPolling(false),
            },
        );
    }

    function reconcile() {
        setReconciling(true);
        router.post(
            '/qbit/reconcile',
            {},
            {
                preserveScroll: true,
                onFinish: () => setReconciling(false),
            },
        );
    }

    function fetchMissingImages() {
        setFetchingImages(true);
        router.post(
            '/images/refresh-missing',
            {},
            {
                preserveScroll: true,
                onFinish: () => setFetchingImages(false),
            },
        );
    }

    function sendTestNotification() {
        setSendingTest(true);
        router.post(
            '/notifications/test',
            {},
            {
                preserveScroll: true,
                onFinish: () => setSendingTest(false),
            },
        );
    }

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-4">
            <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-center">
                <HealthBadge
                    ok={health.qbit.reachable}
                    label="Reachable"
                    tooltip={health.qbit.reachable ? 'qBittorrent is reachable' : "Couldn't reach qBittorrent"}
                />
                <HealthBadge
                    ok={health.qbit.auth}
                    label="Auth"
                    tooltip={health.qbit.auth ? 'Authenticated with qBittorrent' : 'Authentication with qBittorrent failed'}
                />
                <HealthBadge
                    ok={health.qbit.feed}
                    label="Feed"
                    tooltip={health.qbit.feed ? 'RSS feed is registered in qBittorrent' : 'RSS feed is missing from qBittorrent'}
                />
                <HealthBadge
                    ok={health.qbit.prefs}
                    label="Prefs"
                    tooltip={health.qbit.prefs ? 'RSS processing and auto-download are enabled' : 'RSS processing or auto-download is disabled'}
                />
                <HealthBadge
                    ok={health.qbit.category}
                    label="Category"
                    tooltip={health.qbit.category ? 'Download category exists in qBittorrent' : 'Download category is missing in qBittorrent'}
                />
                <span className="col-span-2 text-sm text-muted-foreground sm:col-span-1">
                    {health.qbit.version ?? 'unknown version'} · WebAPI {health.qbit.webapi ?? 'unknown'} · {health.driver} driver ·{' '}
                    {health.pollMode} mode
                </span>
            </div>

            <div className="flex flex-wrap items-center gap-2">
                {notifications.enabled ? (
                    <>
                        <Badge variant="secondary">notifications: {notifications.topic}</Badge>
                        <Button size="sm" variant="outline" className="w-full sm:w-auto" disabled={sendingTest} onClick={sendTestNotification}>
                            {sendingTest ? 'Sending…' : 'Send test notification'}
                        </Button>
                    </>
                ) : (
                    <span className="text-sm text-muted-foreground">notifications off</span>
                )}
            </div>

            {pendingLinkSuggestions > 0 && (
                <Link
                    href="/shows?review=1"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-amber-700 hover:underline dark:text-amber-300"
                >
                    <span className="size-1.5 rounded-full bg-amber-500" aria-hidden />
                    {pendingLinkSuggestions} show{pendingLinkSuggestions === 1 ? ' needs' : 's need'} metadata review
                </Link>
            )}

            {health.bootstrap && <BootstrapLine progress={health.bootstrap} />}

            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                    {health.lastPoll ? (
                        <span>
                            Last poll <RelativeTime iso={health.lastPoll.at} /> — status {health.lastPoll.status ?? 'n/a'}
                            {health.lastPoll.notModified ? ', not modified' : `, ${health.lastPoll.itemsNew} new`}
                            {health.lastPoll.error && <span className="text-destructive"> — {health.lastPoll.error}</span>}
                        </span>
                    ) : (
                        <span>No poll has run yet.</span>
                    )}
                    <span>
                        Next poll{' '}
                        {health.nextPollAt ? <RelativeTime iso={health.nextPollAt} /> : <span className="text-muted-foreground">unscheduled</span>}
                    </span>
                    {health.delay.medianSeconds !== null && health.delay.sampleSize > 0 && (
                        <span>
                            <TapInfo
                                trigger={
                                    <button type="button" className="cursor-pointer bg-transparent p-0 text-left underline decoration-dotted underline-offset-2">
                                        New releases usually spotted within ~{formatLag(health.delay.medianSeconds)}
                                    </button>
                                }
                            >
                                How long Torii typically takes to notice a release after SubsPlease publishes it, based on the last{' '}
                                {health.delay.sampleSize} release{health.delay.sampleSize === 1 ? '' : 's'}. A few minutes is normal. If it suddenly
                                jumps to about an hour, the feed's clock has probably shifted and Torii's time offset needs adjusting.
                            </TapInfo>
                        </span>
                    )}
                </div>

                <div className="grid grid-cols-1 gap-2 sm:flex sm:w-auto sm:gap-2">
                    <Button size="sm" variant="outline" disabled={polling} onClick={forcePoll}>
                        {polling ? 'Polling…' : 'Force poll'}
                    </Button>
                    <Button size="sm" variant="outline" disabled={reconciling} onClick={reconcile}>
                        {reconciling ? 'Reconciling…' : 'Reconcile qBit'}
                    </Button>
                    <Button size="sm" variant="outline" disabled={fetchingImages} onClick={fetchMissingImages}>
                        {fetchingImages ? 'Fetching…' : 'Fetch missing images'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
