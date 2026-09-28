import { AnimeCard } from '@/components/subtracker/anime-card';
import { ANY_FILTERS, AnimeToolbar, navigateAnime } from '@/components/subtracker/anime-toolbar';
import { ShowsPagination } from '@/components/subtracker/shows-pagination';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { animeSeasonLabel } from '@/lib/anime';
import { type BreadcrumbItem } from '@/types';
import { type AnimeIndexProps } from '@/types/subtracker';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Anime', href: '/anime' }];

export default function AnimeIndex({ anime, filters, filterOptions }: AnimeIndexProps) {
    const unfiltered = filters.season === null && filters.year === null && filters.status === null && filters.genre === null && filters.linked === 'all';
    const scope = animeSeasonLabel(filters.season, filters.year);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anime" />
            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <AnimeToolbar filters={filters} options={filterOptions} />
                    <span className="text-sm text-muted-foreground">
                        {anime.meta.total} title{anime.meta.total === 1 ? '' : 's'}
                        {scope ? ` · ${scope}` : ''}
                    </span>
                </div>

                {anime.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border p-8 text-center text-sm text-muted-foreground">
                        {unfiltered ? (
                            <p>No anime synced yet — the weekly sync fills this in.</p>
                        ) : (
                            <>
                                <p>No anime match these filters.</p>
                                <Button variant="outline" size="sm" onClick={() => navigateAnime(ANY_FILTERS)}>
                                    Show all anime
                                </Button>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-3 sm:gap-4 [grid-template-columns:repeat(auto-fill,minmax(150px,1fr))] sm:[grid-template-columns:repeat(auto-fill,minmax(180px,1fr))] lg:[grid-template-columns:repeat(auto-fill,minmax(200px,1fr))]">
                        {anime.data.map((item) => (
                            <AnimeCard key={item.id} anime={item} />
                        ))}
                    </div>
                )}

                {anime.meta.last_page > 1 && (
                    <div className="sticky bottom-0 z-30 -mx-3 border-t bg-background/95 px-3 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:-mx-4 sm:px-4">
                        <ShowsPagination links={anime.meta.links} onNavigate={(url) => router.get(url, {}, { preserveState: true, preserveScroll: true })} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
