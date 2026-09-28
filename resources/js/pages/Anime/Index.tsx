import { AnimeCard } from '@/components/subtracker/anime-card';
import { ANY_FILTERS, AnimeToolbar, animeQueryParams } from '@/components/subtracker/anime-toolbar';
import { GenreChips } from '@/components/subtracker/facet-filters';
import { ShowsPagination } from '@/components/subtracker/shows-pagination';
import { Button } from '@/components/ui/button';
import { useFilterNavigation } from '@/hooks/use-filter-navigation';
import AppLayout from '@/layouts/app-layout';
import { animeSeasonLabel } from '@/lib/anime';
import { POSTER_GRID } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type AnimeIndexProps } from '@/types/subtracker';
import { Head, router } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Anime', href: '/anime' }];

export default function AnimeIndex({ anime, filters, filterOptions }: AnimeIndexProps) {
    const nav = useFilterNavigation('/anime', filters, animeQueryParams);

    const unfiltered =
        filters.q === '' &&
        filters.season === null &&
        filters.year === null &&
        filters.status === null &&
        filters.linked === 'all' &&
        filters.format.length === 0 &&
        filters.genresInclude.length === 0 &&
        filters.genresExclude.length === 0;
    const scope = animeSeasonLabel(filters.season, filters.year);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Anime" />
            <div className="flex h-full flex-1 flex-col gap-4 p-3 sm:p-4">
                <div className="flex flex-col gap-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <AnimeToolbar filters={nav.filters} options={filterOptions} nav={nav} />
                        <span className="text-sm text-muted-foreground">
                            {anime.meta.total} title{anime.meta.total === 1 ? '' : 's'}
                            {scope ? ` · ${scope}` : ''}
                        </span>
                    </div>
                    <GenreChips
                        include={nav.filters.genresInclude}
                        exclude={nav.filters.genresExclude}
                        onChange={(genresInclude, genresExclude) => nav.submit({ genresInclude, genresExclude })}
                    />
                </div>

                {anime.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-3 rounded-xl border p-8 text-center text-sm text-muted-foreground">
                        {unfiltered ? (
                            <p>No anime synced yet — the weekly sync fills this in.</p>
                        ) : (
                            <>
                                <p>No anime match these filters.</p>
                                <Button variant="outline" size="sm" onClick={() => nav.navigate(ANY_FILTERS)}>
                                    Show all anime
                                </Button>
                            </>
                        )}
                    </div>
                ) : (
                    <div className={POSTER_GRID}>
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
