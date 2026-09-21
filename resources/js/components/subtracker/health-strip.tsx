import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type Health } from '@/types/subtracker';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { RelativeTime } from './relative-time';

interface HealthStripProps {
    health: Health;
}

function HealthBadge({ ok, label, tooltip }: { ok: boolean; label: string; tooltip: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Badge
                    variant={ok ? 'default' : 'destructive'}
                    className={ok ? 'border-transparent bg-green-600 text-white hover:bg-green-600/90' : undefined}
                >
                    {label}
                </Badge>
            </TooltipTrigger>
            <TooltipContent>{tooltip}</TooltipContent>
        </Tooltip>
    );
}

export function HealthStrip({ health }: HealthStripProps) {
    const [polling, setPolling] = useState(false);
    const [reconciling, setReconciling] = useState(false);

    function forcePoll() {
        setPolling(true);
        router.post(
            '/feed/poll',
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Feed poll finished'),
                onError: () => toast.error('Feed poll failed'),
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
                onSuccess: () => toast.success('Reconcile finished'),
                onError: () => toast.error('Reconcile failed'),
                onFinish: () => setReconciling(false),
            },
        );
    }

    return (
        <section className="flex flex-col gap-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-center gap-2">
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
                <span className="text-sm text-muted-foreground">
                    v{health.qbit.version ?? 'unknown'} · WebAPI {health.qbit.webapi ?? 'unknown'} · {health.driver} driver · {health.pollMode} mode
                </span>
            </div>

            <div className="flex flex-wrap items-center justify-between gap-3">
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
                </div>

                <div className="flex gap-2">
                    <Button size="sm" variant="outline" disabled={polling} onClick={forcePoll}>
                        {polling ? 'Polling…' : 'Force poll'}
                    </Button>
                    <Button size="sm" variant="outline" disabled={reconciling} onClick={reconcile}>
                        {reconciling ? 'Reconciling…' : 'Reconcile qBit'}
                    </Button>
                </div>
            </div>
        </section>
    );
}
