import { HealthStrip } from '@/components/subtracker/health-strip';
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
            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <HealthStrip health={health} />

                <section className="flex flex-col gap-2">
                    <h2 className="text-lg font-medium">Latest releases</h2>
                    <ReleasesTable releases={latestReleases} showColumn emptyMessage="No releases yet." />
                </section>
            </div>
        </AppLayout>
    );
}
