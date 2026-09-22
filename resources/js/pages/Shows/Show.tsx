import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { LatestEpisodeLabel } from '@/components/subtracker/latest-episode-label';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { QueueMissingButton } from '@/components/subtracker/queue-missing-button';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { ReleaseCard } from '@/components/subtracker/release-card';
import { ReleasesTable } from '@/components/subtracker/releases-table';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { SeasonLabel } from '@/components/subtracker/season-label';
import { ShowPoster } from '@/components/subtracker/show-poster';
import { TapInfo } from '@/components/subtracker/tap-info';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Button } from '@/components/ui/button';
import { useRecentActivity } from '@/hooks/use-recent-activity';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type LatestRelease, type RuleState, type ShowShowProps } from '@/types/subtracker';
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
            <TapInfo trigger={<button type="button" className="cursor-pointer bg-transparent p-0 text-destructive underline decoration-dotted">error checking image</button>}>
                {show.imageError ?? 'Unknown error'}
            </TapInfo>
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

    // `releases` is already sorted newest-first by published_at, so [0] is the latest —
    // used instead of `show.latest`, which the controller doesn't eager-load on this page.
    const firstRelease = releases[0];
    const latest: LatestRelease | null = firstRelease
        ? {
              episode: firstRelease.episode,
              isBatch: firstRelease.isBatch,
              batchFrom: firstRelease.batchFrom,
              batchTo: firstRelease.batchTo,
              publishedAt: firstRelease.publishedAt,
          }
        : null;

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

    const canDeleteRule = DELETABLE_RULE_STATES.includes(show.ruleState);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={show.name} />
            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                <div className="flex flex-col gap-4 rounded-xl border p-3 sm:p-4 md:flex-row">
                    <div className="mx-auto flex w-full max-w-[min(280px,70vw)] shrink-0 flex-col items-center gap-2 md:mx-0 md:w-80 md:max-w-none">
                        <ShowPoster
                            imageUrl={show.imageUrl}
                            imageStatus={show.imageStatus}
                            imageWidth={show.imageWidth}
                            imageHeight={show.imageHeight}
                            name={show.name}
                            className="w-full"
                        />
                        <Button variant="outline" size="sm" disabled={refreshing} onClick={reloadImage}>
                            {refreshing ? 'Reloading…' : 'Reload image'}
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            <ImageStatusLine show={show} />
                        </p>
                    </div>

                    <div className="flex min-w-0 flex-1 flex-col gap-3">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="min-w-0 flex-1 text-xl font-medium break-words">{show.name}</h1>

                            <div className="hidden items-center gap-2 md:flex">
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
                                {canDeleteRule && (
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

                        <div className="flex flex-wrap items-center gap-3">
                            <TrackSwitch
                                showId={show.id}
                                showName={show.name}
                                tracked={show.isTracked}
                                downloadableCount={show.downloadableCount}
                                hasBatch={show.hasBatch}
                            />
                            <RuleBadge trackingMode={show.trackingMode} state={show.ruleState} error={show.ruleError} />
                        </div>

                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <LatestEpisodeLabel latest={latest} />
                            <SeasonLabel season={show.season} seasonYear={show.seasonYear} premiereSource={show.premiereSource} />
                        </div>

                        <p className="text-sm text-muted-foreground">
                            First seen: <RelativeTime iso={show.firstSeenAt} /> · Last seen: <RelativeTime iso={show.lastSeenAt} /> ·{' '}
                            {show.queuedCount} release{show.queuedCount === 1 ? '' : 's'} queued · {show.downloadedCount} downloaded
                        </p>

                        {/* Phone/tablet: full-width wrapping action group. md+: the inline buttons above. */}
                        <div className="flex flex-wrap gap-2 md:hidden">
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
                            {canDeleteRule && (
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
                </div>

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Releases</h2>

                    {releases.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">No releases for this show yet.</p>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2 md:hidden">
                                {releases.map((release) => (
                                    <ReleaseCard key={release.id} release={release} />
                                ))}
                            </div>
                            <div className="hidden md:block">
                                <ReleasesTable releases={releases} versionColumn emptyMessage="No releases for this show yet." />
                            </div>
                        </>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
