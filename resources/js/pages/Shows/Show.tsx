import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { QueueMissingButton } from '@/components/subtracker/queue-missing-button';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { ReleasesTable } from '@/components/subtracker/releases-table';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { type RuleState, type ShowShowProps } from '@/types/subtracker';
import { Head, usePage } from '@inertiajs/react';

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

export default function ShowShow({ show, releases }: ShowShowProps) {
    const { driver } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Shows', href: '/shows' },
        { title: show.name, href: `/shows/${show.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={show.name} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-col gap-3 rounded-xl border p-4">
                    <div className="flex flex-wrap items-center gap-3">
                        <h1 className="text-xl font-medium">{show.name}</h1>
                        <TrackSwitch showId={show.id} showName={show.name} tracked={show.isTracked} />
                        <RuleBadge trackingMode={show.trackingMode} state={show.ruleState} error={show.ruleError} />

                        <div className="ml-auto flex items-center gap-2">
                            <QueueMissingButton showId={show.id} downloadableCount={show.downloadableCount} />
                            {driver === 'rules' && (
                                <MatchesDialog
                                    showId={show.id}
                                    showName={show.name}
                                    trigger={
                                        <Button variant="outline" size="sm">
                                            Preview matches
                                        </Button>
                                    }
                                />
                            )}
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
                        <RelativeTime iso={show.lastSeenAt} /> · Queued: {show.queuedCount}
                    </p>
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Releases</h2>
                    <ReleasesTable releases={releases} versionColumn emptyMessage="No releases for this show yet." />
                </section>
            </div>
        </AppLayout>
    );
}
