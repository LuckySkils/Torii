import { badgeVariants } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatLag } from '@/lib/dates';
import { ACTION_HINTS } from '@/lib/hints';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { type BootstrapProgress, type BootstrapTaskState, type Health, type HealthCheck } from '@/types/subtracker';
import { Link, router, usePage } from '@inertiajs/react';
import { Check, Circle, Loader2, TriangleAlert, X } from 'lucide-react';
import { useState } from 'react';
import { ActionHint, Hint } from './hint';
import { RelativeTime } from './relative-time';
import { TapInfo } from './tap-info';

interface HealthStripProps {
    /** Missing only if the dashboard couldn't build it; that is itself reported as a problem. */
    health: Health | null | undefined;
}

const UNAVAILABLE: HealthCheck = {
    key: 'health.unavailable',
    ok: false,
    label: 'Health status unavailable',
    detail: "The dashboard couldn't load its health checks, so qBittorrent, the feed and notifications weren't checked.",
    skipped: false,
};

/** A failing check, plus the checks that weren't run because of it. */
interface Problem {
    check: HealthCheck;
    notChecked: HealthCheck[];
}

/** The checks whose failure stops the others from running; skipped ones fold into the first that failed. */
const BLOCKING_KEYS = ['qbit.reachable', 'qbit.auth'];

/**
 * Only real failures get a badge: `ok: false` and not `skipped`. Skipped checks
 * are listed inside the failure that caused them. A skipped check with no such
 * cause (not expected) keeps its own badge rather than disappearing.
 */
function problemsOf(health: Health | null | undefined): Problem[] {
    if (!health || !Array.isArray(health.checks)) {
        return [{ check: UNAVAILABLE, notChecked: [] }];
    }

    const failing = health.checks.filter((check) => !check.ok);
    const problems: Problem[] = failing.filter((check) => !check.skipped).map((check) => ({ check, notChecked: [] }));
    const cause = problems.find((problem) => BLOCKING_KEYS.includes(problem.check.key));

    for (const check of failing.filter((check) => check.skipped)) {
        if (cause) {
            cause.notChecked.push(check);
        } else {
            problems.push({ check, notChecked: [] });
        }
    }

    return problems;
}

/** Short names for the folded list: "Feed, RSS settings and category weren't checked." */
const SHORT_NAMES: Record<string, string> = {
    'qbit.auth': 'login',
    'qbit.feed': 'feed',
    'qbit.prefs': 'RSS settings',
    'qbit.category': 'category',
};

function notCheckedSentence(checks: HealthCheck[]): string {
    const names = checks.map((check) => SHORT_NAMES[check.key] ?? check.label);
    const list = names.length === 1 ? names[0] : `${names.slice(0, -1).join(', ')} and ${names.at(-1)}`;

    return `${list.charAt(0).toUpperCase()}${list.slice(1)} ${names.length === 1 ? "wasn't" : "weren't"} checked.`;
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
                            <span
                                className="block h-full rounded-full bg-sky-500/70"
                                style={{ width: `${(progress.completed / Math.max(progress.total, 1)) * 100}%` }}
                            />
                        </span>
                    </button>
                }
            >
                <p className="mb-2 text-xs text-muted-foreground">
                    One-time tasks that fill in data after a fresh install. Nothing to do; they run in the background.
                </p>
                <ul className="flex flex-col gap-1.5">
                    {progress.tasks.map((task) => {
                        const { icon: Icon, className, label } = TASK_ICONS[task.state];

                        return (
                            <li key={task.key} className="flex items-start gap-2">
                                <Icon className={`mt-0.5 size-3.5 shrink-0 ${className}`} aria-label={label} />
                                <span className="flex min-w-0 flex-col">
                                    <span className={task.state === 'done' ? 'text-muted-foreground' : undefined}>{task.label}</span>
                                    {task.error && (
                                        <span className="text-xs break-words text-destructive">{task.error} · retried on the next start</span>
                                    )}
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
    const problems = problemsOf(health);

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
            {problems.length > 0 && (
                <div className="flex flex-wrap items-center gap-2" role="list" aria-label="Health problems">
                    {problems.map((problem) => (
                        <ProblemBadge key={problem.check.key} problem={problem} />
                    ))}
                </div>
            )}

            {health && (
                <p className="text-sm text-muted-foreground">
                    qBittorrent {health.qbit.version ?? 'version unknown'} · WebAPI {health.qbit.webapi ?? 'unknown'} · {health.driver} driver ·{' '}
                    {health.pollMode} mode · {notifications.enabled ? `notifications to ${notifications.topic}` : 'notifications off'}
                </p>
            )}

            {pendingLinkSuggestions > 0 && (
                <Link
                    href="/shows?review=1"
                    className="inline-flex w-fit items-center gap-1.5 text-sm text-amber-700 hover:underline dark:text-amber-300"
                >
                    <span className="size-1.5 rounded-full bg-amber-500" aria-hidden />
                    {pendingLinkSuggestions} show{pendingLinkSuggestions === 1 ? ' needs' : 's need'} metadata review
                </Link>
            )}

            {health?.bootstrap && <BootstrapLine progress={health.bootstrap} />}

            <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
                {health && (
                    <div className="flex flex-col gap-1 text-sm text-muted-foreground">
                        {health.lastPoll ? (
                            <span>
                                Last poll <RelativeTime iso={health.lastPoll.at} /> — status {health.lastPoll.status ?? 'n/a'}
                                {health.lastPoll.error
                                    ? ', failed'
                                    : health.lastPoll.notModified
                                      ? ', not modified'
                                      : `, ${health.lastPoll.itemsNew} new`}
                            </span>
                        ) : (
                            <span>No poll has run yet.</span>
                        )}
                        <span>
                            Next poll{' '}
                            {health.nextPollAt ? (
                                <RelativeTime iso={health.nextPollAt} />
                            ) : (
                                <span className="text-muted-foreground">unscheduled</span>
                            )}
                        </span>
                        {health.delay.medianSeconds !== null && health.delay.sampleSize > 0 && (
                            <span>
                                <TapInfo
                                    trigger={
                                        <button
                                            type="button"
                                            className="cursor-pointer bg-transparent p-0 text-left underline decoration-dotted underline-offset-2"
                                        >
                                            New releases usually spotted within ~{formatLag(health.delay.medianSeconds)}
                                        </button>
                                    }
                                >
                                    How long Torii typically takes to notice a release after SubsPlease publishes it, based on the last{' '}
                                    {health.delay.sampleSize} release{health.delay.sampleSize === 1 ? '' : 's'}. A few minutes is normal. If it
                                    suddenly jumps to about an hour, the feed's clock has probably shifted and Torii's time offset needs adjusting.
                                </TapInfo>
                            </span>
                        )}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-2 sm:ml-auto sm:flex sm:w-auto sm:flex-wrap sm:gap-2">
                    <ActionHint hint={ACTION_HINTS.forcePoll} className="w-full sm:w-auto">
                        <Button size="sm" variant="outline" className="flex-1" disabled={polling} onClick={forcePoll}>
                            {polling ? 'Polling…' : 'Force poll'}
                        </Button>
                    </ActionHint>
                    <ActionHint hint={ACTION_HINTS.reconcile} className="w-full sm:w-auto">
                        <Button size="sm" variant="outline" className="flex-1" disabled={reconciling} onClick={reconcile}>
                            {reconciling ? 'Reconciling…' : 'Reconcile qBit'}
                        </Button>
                    </ActionHint>
                    <ActionHint hint={ACTION_HINTS.fetchMissingImages} className="w-full sm:w-auto">
                        <Button size="sm" variant="outline" className="flex-1" disabled={fetchingImages} onClick={fetchMissingImages}>
                            {fetchingImages ? 'Fetching…' : 'Fetch missing images'}
                        </Button>
                    </ActionHint>
                    {notifications.enabled && (
                        <ActionHint hint={ACTION_HINTS.sendTestNotification} className="w-full sm:w-auto">
                            <Button size="sm" variant="outline" className="flex-1" disabled={sendingTest} onClick={sendTestNotification}>
                                {sendingTest ? 'Sending…' : 'Send test notification'}
                            </Button>
                        </ActionHint>
                    )}
                </div>
            </div>
        </section>
    );
}

/** One failing check, its detail (and what it stopped from running) on hover (desktop) or tap (touch). */
function ProblemBadge({ problem: { check, notChecked } }: { problem: Problem }) {
    return (
        <Hint
            trigger={
                <button type="button" role="listitem" className={cn(badgeVariants({ variant: 'destructive' }), 'cursor-help gap-1.5 py-1')}>
                    <TriangleAlert className="size-3.5" aria-hidden />
                    {check.label}
                </button>
            }
        >
            <span className="block">{check.detail ?? 'This check failed without saying why.'}</span>
            {notChecked.length > 0 && <span className="mt-1 block text-muted-foreground">{notCheckedSentence(notChecked)}</span>}
        </Hint>
    );
}
