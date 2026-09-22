import { HealthStrip } from '@/components/subtracker/health-strip';
import { ReleaseCard } from '@/components/subtracker/release-card';
import { ReleasesTable } from '@/components/subtracker/releases-table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type DashboardProps } from '@/types/subtracker';
import { Head, usePoll } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/' }];

export default function Dashboard({ health, latestReleases }: DashboardProps) {
    usePoll(30000, { only: ['health', 'latestReleases'] });

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
                                    <ReleaseCard key={release.id} release={release} showLink />
                                ))}
                            </div>
                            <div className="hidden md:block">
                                <ReleasesTable releases={latestReleases} showColumn emptyMessage="No releases yet." />
                            </div>
                        </>
                    )}
                </section>
            </div>
        </AppLayout>
    );
}
