import { HealthStrip } from '@/components/subtracker/health-strip';
import { LatestReleasesTable, ReleaseEpisode, ReleaseThumb } from '@/components/subtracker/latest-releases-table';
import { FirstEpisodeBadge, isPremiere, NewShowBadge } from '@/components/subtracker/novelty-badges';
import { ReleaseCard } from '@/components/subtracker/release-card';
import { useAdaptivePoll } from '@/hooks/use-adaptive-poll';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DashboardProps } from '@/types/subtracker';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/' }];

export default function Dashboard({ health, latestReleases }: DashboardProps) {
    const anyPosterPending = latestReleases.some((release) => release.show?.imageStatus === 'pending');
    useAdaptivePoll(anyPosterPending ? 5000 : 30000, ['health', 'latestReleases']);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-6 p-3 sm:p-4">
                <HealthStrip health={health} />

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Latest releases</h2>

                    {latestReleases.length === 0 ? (
                        <p className="p-4 text-sm text-muted-foreground">No releases yet.</p>
                    ) : (
                        <>
                            <div className="flex flex-col gap-2 md:hidden">
                                {latestReleases.map((release) => (
                                    <ReleaseCard
                                        key={release.id}
                                        release={release}
                                        showLink
                                        poster={<ReleaseThumb release={release} className="w-24" />}
                                        episode={<ReleaseEpisode release={release} />}
                                        badges={
                                            release.isNewShow || isPremiere(release) ? (
                                                <>
                                                    {release.isNewShow && <NewShowBadge />}
                                                    {isPremiere(release) && <FirstEpisodeBadge />}
                                                </>
                                            ) : undefined
                                        }
                                    />
                                ))}
                            </div>
                            <div className="hidden md:block">
                                <LatestReleasesTable releases={latestReleases} />
                            </div>
                        </>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
