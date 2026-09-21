import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { QueueMissingButton } from '@/components/subtracker/queue-missing-button';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { ReleasesTable } from '@/components/subtracker/releases-table';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { ShowPoster } from '@/components/subtracker/show-poster';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useRecentActivity } from '@/hooks/use-recent-activity';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type RuleState, type ShowShowProps } from '@/types/subtracker';
import { Head, router, usePoll } from '@inertiajs/react';
import { useState } from 'react';

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

function ImageStatusLine({ show }: { show: ShowShowProps['show'] }) {
    if (show.imageStatus === 'found') {
        return show.imageCheckedAt ? (
            <span>
                checked <RelativeTime iso={show.imageCheckedAt} />
            </span>
        ) : (
            <span>checked recently</span>
        );
    }

    if (show.imageStatus === 'missing') {
        return <span>no image on SubsPlease yet</span>;
    }

    if (show.imageStatus === 'error') {
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="cursor-default text-destructive underline decoration-dotted">error checking image</span>
                </TooltipTrigger>
                <TooltipContent>{show.imageError ?? 'Unknown error'}</TooltipContent>
            </Tooltip>
        );
    }

    if (show.imageStatus === 'pending') {
        return <span>checking…</span>;
    }

    return <span>not checked yet</span>;
}

export default function ShowShow({ show, releases }: ShowShowProps) {
    const [refreshing, setRefreshing] = useState(false);

    const recentActivity = useRecentActivity();
    const isActive = recentActivity || show.ruleState === 'pending' || show.imageStatus === 'pending';
    usePoll(isActive ? 5000 : 60000, { only: ['show', 'releases'] });

    function reloadImage() {
        setRefreshing(true);
        router.post(
            `/shows/${show.id}/image/refresh`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setRefreshing(false),
            },
        );
    }

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shows', href: '/shows' },
        { title: show.name, href: `/shows/${show.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={show.name} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-4 rounded-xl border p-4 sm:flex-row">
                    <div className="flex w-40 shrink-0 flex-col items-center gap-2">
                        <ShowPoster imageUrl={show.imageUrl} imageStatus={show.imageStatus} name={show.name} className="w-40" />
                        <Button variant="outline" size="sm" disabled={refreshing} onClick={reloadImage}>
                            {refreshing ? 'Reloading…' : 'Reload image'}
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            <ImageStatusLine show={show} />
                        </p>
                    </div>

                    <div className="flex flex-1 flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-xl font-medium">{show.name}</h1>
                            <TrackSwitch
                                showId={show.id}
                                showName={show.name}
                                tracked={show.isTracked}
                                downloadableCount={show.downloadableCount}
                                hasBatch={show.hasBatch}
                            />
                            <RuleBadge trackingMode={show.trackingMode} state={show.ruleState} error={show.ruleError} />

                            <div className="ml-auto flex items-center gap-2">
                                <QueueMissingButton showId={show.id} downloadableCount={show.downloadableCount} />
                                <MatchesDialog
                                    showId={show.id}
                                    showName={show.name}
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            Preview matches
                                        </Button>
                                    }
                                />
                                {DELETABLE_RULE_STATES.includes(show.ruleState) && (
                                    <DeleteRuleDialog
                                        showId={show.id}
                                        showName={show.name}
                                        trigger={
                                            <Button variant="destructive" size="sm">
                                                Delete rule
                                            </Button>
                                        }
                                    />
                                )}
                            </div>
                        </div>

                        <p className="text-sm text-muted-foreground">
                            Latest episode: {show.latestEpisode ?? '—'} · First seen: <RelativeTime iso={show.firstSeenAt} /> · Last seen:{' '}
                            <RelativeTime iso={show.lastSeenAt} /> · {show.queuedCount} release{show.queuedCount === 1 ? '' : 's'} queued
                        </p>
                    </div>
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Releases</h2>
                    <ReleasesTable releases={releases} versionColumn emptyMessage="No releases for this show yet." />
                </section>
            </div>
        </AppLayout>
    );
}
