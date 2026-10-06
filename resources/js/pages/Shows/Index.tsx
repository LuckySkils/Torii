import { AiredAheadLine } from '@/components/subtracker/aired-ahead-line';
import { AnimeLinkIndicator } from '@/components/subtracker/anime-link-indicator';
import { BatchHint } from '@/components/subtracker/batch-hint';
import { BulkBar } from '@/components/subtracker/bulk-bar';
import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { GenreChips } from '@/components/subtracker/facet-filters';
import { ActionHint, ColumnHint } from '@/components/subtracker/hint';
import { LatestEpisodeLabel } from '@/components/subtracker/latest-episode-label';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { NyaaImportButton } from '@/components/subtracker/nyaa-import-dialog';
import { PosterHoverPreview } from '@/components/subtracker/poster-hover-preview';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { SeasonLabel } from '@/components/subtracker/season-label';
import { ShowCard } from '@/components/subtracker/show-card';
import { ShowListRowMobile } from '@/components/subtracker/show-list-row-mobile';
import { ShowsPagination } from '@/components/subtracker/shows-pagination';
import { clearedShowFilters, showsQueryParams, ShowsToolbar } from '@/components/subtracker/shows-toolbar';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useAdaptivePoll } from '@/hooks/use-adaptive-poll';
import { useFilterNavigation } from '@/hooks/use-filter-navigation';
import { useRecentActivity } from '@/hooks/use-recent-activity';
import { useShowsView } from '@/hooks/use-shows-view';
import AppLayout from '@/layouts/app-layout';
import { latestEpisodeNumber, posterFor } from '@/lib/anime';
import { ACTION_HINTS, COLUMN_HINTS } from '@/lib/hints';
import { POSTER_GRID } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { type RuleState, type ShowsIndexProps } from '@/types/subtracker';
import { Head, Link } from '@inertiajs/react';
import { Eye, LayoutGrid, List, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shows', href: '/shows' }];

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

export default function ShowsIndex({ shows, filters, filterOptions }: ShowsIndexProps) {
    const nav = useFilterNavigation('/shows', filters, showsQueryParams);
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [selectionMode, setSelectionMode] = useState(false);
    const [view, setView] = useShowsView();

    const recentActivity = useRecentActivity();
    const hasPendingRow = shows.data.some((show) => show.ruleState === 'pending' || show.imageStatus === 'pending');
    useAdaptivePoll(hasPendingRow || recentActivity ? 5000 : 60000, ['shows']);

    useEffect(() => {
        setSelectedIds([]);
        setSelectionMode(false);
    }, [
        filters.q,
        filters.tracked,
        filters.sort,
        filters.season,
        filters.year,
        filters.review,
        filters.format,
        filters.genresInclude,
        filters.genresExclude,
        shows.meta.current_page,
    ]);

    const pageIds = shows.data.map((show) => show.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));

    function toggleSelectAll(checked: boolean) {
        setSelectedIds(checked ? pageIds : []);
    }

    function toggleSelect(id: number, checked: boolean) {
        setSelectedIds((current) => (checked ? [...current, id] : current.filter((selectedId) => selectedId !== id)));
    }

    function clearFilters() {
        nav.navigate(clearedShowFilters(filters.sort));
    }

    const otherFilters =
        filters.q !== '' ||
        filters.tracked !== 'all' ||
        filters.season !== null ||
        filters.year !== null ||
        filters.format.length > 0 ||
        filters.genresInclude.length > 0 ||
        filters.genresExclude.length > 0;
    const isFiltered = filters.review || otherFilters;
    // Review is the only filter on: an empty result means there's simply nothing to review.
    const onlyReviewFilter = filters.review && !otherFilters;
    const isEmptyCatalog = shows.meta.total === 0 && !isFiltered;
    const isEmptyResults = shows.data.length === 0 && !isEmptyCatalog;
    const bulkBarVisible = selectedIds.length > 0;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shows" />
            <div className={`flex h-full flex-1 flex-col gap-4 p-3 sm:p-4 ${bulkBarVisible ? 'pb-24 sm:pb-4' : ''}`}>
                <div className="flex flex-wrap items-center justify-between gap-2 sm:flex-nowrap sm:items-start">
                    <ShowsToolbar
                        filters={nav.filters}
                        options={filterOptions}
                        nav={nav}
                        view={view}
                        onViewChange={setView}
                        phoneActions={<NyaaImportButton label="Import" />}
                    />
                    <NyaaImportButton className="hidden shrink-0 sm:inline-flex" />
                    <ToggleGroup
                        type="single"
                        value={view}
                        onValueChange={(value) => value && setView(value as 'grid' | 'list')}
                        aria-label="Shows view"
                        className="hidden shrink-0 sm:flex"
                    >
                        <ToggleGroupItem value="grid" aria-label="Grid view">
                            <LayoutGrid className="size-4" />
                        </ToggleGroupItem>
                        <ToggleGroupItem value="list" aria-label="List view">
                            <List className="size-4" />
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>

                <GenreChips
                    className="-mt-2"
                    include={nav.filters.genresInclude}
                    exclude={nav.filters.genresExclude}
                    onChange={(genresInclude, genresExclude) => nav.submit({ genresInclude, genresExclude })}
                />
                {(filters.format.length > 0 || filters.genresInclude.length > 0) && (
                    <p className="-mt-2 text-xs text-muted-foreground">
                        Format and included genres come from the linked anime, so unlinked shows are left out.
                    </p>
                )}

                {view === 'grid' && shows.data.length > 0 && (
                    <div className="flex items-center justify-between gap-2 sm:hidden">
                        <span className="text-sm text-muted-foreground">
                            {shows.meta.total} show{shows.meta.total === 1 ? '' : 's'}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                setSelectionMode((mode) => !mode);
                                setSelectedIds([]);
                            }}
                        >
                            {selectionMode ? 'Done' : 'Select'}
                        </Button>
                    </div>
                )}

                <BulkBar shows={shows.data} selectedIds={selectedIds} onClear={() => setSelectedIds([])} />

                {isEmptyCatalog && (
                    <div className="flex flex-col items-center gap-2 rounded-xl border p-8 text-center text-sm text-muted-foreground">
                        <p>Catalog is empty; the feed hasn't been polled yet.</p>
                        <Link href="/" className="text-primary hover:underline">
                            Go to Dashboard
                        </Link>
                    </div>
                )}

                {isEmptyResults && (
                    <div className="flex flex-col items-center gap-2 rounded-xl border p-8 text-center text-sm text-muted-foreground">
                        <p>{onlyReviewFilter ? 'Nothing needs review right now.' : 'No shows match your filters.'}</p>
                        <Button variant="outline" size="sm" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    </div>
                )}

                {filters.review && shows.data.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {shows.meta.total} show{shows.meta.total === 1 ? '' : 's'} with anime link suggestions waiting. Open the amber{' '}
                        <span className="font-medium text-amber-700 dark:text-amber-300">review</span> badge to pick the right entry.
                    </p>
                )}

                {shows.data.length > 0 && view === 'grid' && (
                    <div className={POSTER_GRID}>
                        {shows.data.map((show) => (
                            <ShowCard
                                key={show.id}
                                show={show}
                                selected={selectedIds.includes(show.id)}
                                onToggleSelect={(checked) => toggleSelect(show.id, checked)}
                                selectionMode={selectionMode}
                            />
                        ))}
                    </div>
                )}

                {shows.data.length > 0 && view === 'list' && (
                    <>
                        <div className="rounded-xl border md:hidden">
                            {shows.data.map((show) => (
                                <ShowListRowMobile key={show.id} show={show} />
                            ))}
                        </div>

                        <div className="hidden overflow-x-auto rounded-xl border md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-10">
                                            <Checkbox
                                                checked={allSelected}
                                                onCheckedChange={(checked) => toggleSelectAll(checked === true)}
                                                aria-label="Select all shows on this page"
                                            />
                                        </TableHead>
                                        <TableHead className="w-20" />
                                        <TableHead>
                                            <ColumnHint label="Name">{COLUMN_HINTS.showName}</ColumnHint>
                                        </TableHead>
                                        <TableHead>
                                            <ColumnHint label="Latest">{COLUMN_HINTS.latest}</ColumnHint>
                                        </TableHead>
                                        <TableHead>
                                            <ColumnHint label="Last seen">{COLUMN_HINTS.lastSeen}</ColumnHint>
                                        </TableHead>
                                        <TableHead>
                                            <ColumnHint label="Track">{COLUMN_HINTS.track}</ColumnHint>
                                        </TableHead>
                                        <TableHead>
                                            <ColumnHint label="Rule">{COLUMN_HINTS.rule}</ColumnHint>
                                        </TableHead>
                                        <TableHead className="w-20" />
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {shows.data.map((show) => (
                                        <TableRow key={show.id}>
                                            <TableCell>
                                                <Checkbox
                                                    checked={selectedIds.includes(show.id)}
                                                    onCheckedChange={(checked) => toggleSelect(show.id, checked === true)}
                                                    aria-label={`Select ${show.name}`}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <PosterHoverPreview {...posterFor(show)} name={show.name} />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col gap-0.5">
                                                    <div className="flex items-center gap-1.5">
                                                        <Link href={`/shows/${show.id}`} className="font-medium hover:underline">
                                                            {show.name}
                                                        </Link>
                                                        {show.hasBatch && !show.isTracked && <BatchHint />}
                                                        <AnimeLinkIndicator show={show} currentEpisode={latestEpisodeNumber(show.latest)} />
                                                    </div>
                                                    <SeasonLabel
                                                        season={show.season}
                                                        seasonYear={show.seasonYear}
                                                        premiereSource={show.premiereSource}
                                                        className="text-xs text-muted-foreground"
                                                    />
                                                    <AiredAheadLine show={show} />
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <LatestEpisodeLabel latest={show.latest} />
                                            </TableCell>
                                            <TableCell>
                                                <RelativeTime iso={show.lastSeenAt} />
                                            </TableCell>
                                            <TableCell>
                                                <TrackSwitch
                                                    showId={show.id}
                                                    showName={show.name}
                                                    tracked={show.isTracked}
                                                    downloadableCount={show.downloadableCount}
                                                    hasBatch={show.hasBatch}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <RuleBadge trackingMode={show.trackingMode} state={show.ruleState} error={show.ruleError} />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex items-center gap-1">
                                                    <MatchesDialog
                                                        showId={show.id}
                                                        showName={show.name}
                                                        trigger={
                                                            <ActionHint hint={ACTION_HINTS.previewMatches} touchInfo={false}>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="icon"
                                                                    className="size-8"
                                                                    aria-label={`Preview matches for ${show.name}`}
                                                                >
                                                                    <Eye className="size-4" />
                                                                </Button>
                                                            </ActionHint>
                                                        }
                                                    />
                                                    {DELETABLE_RULE_STATES.includes(show.ruleState) && (
                                                        <DeleteRuleDialog
                                                            showId={show.id}
                                                            showName={show.name}
                                                            trigger={
                                                                <ActionHint hint={ACTION_HINTS.deleteRule} touchInfo={false}>
                                                                    <Button
                                                                        variant="ghost"
                                                                        size="icon"
                                                                        className="size-8 text-destructive hover:text-destructive"
                                                                        aria-label={`Delete rule for ${show.name}`}
                                                                    >
                                                                        <Trash2 className="size-4" />
                                                                    </Button>
                                                                </ActionHint>
                                                            }
                                                        />
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </>
                )}

                {shows.data.length > 0 && (
                    <div
                        className={`sticky z-30 -mx-3 border-t bg-background/95 px-3 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:bottom-0 sm:-mx-4 sm:px-4 ${bulkBarVisible ? 'bottom-16' : 'bottom-0'}`}
                    >
                        <ShowsPagination links={shows.meta.links} hrefFor={nav.pageHref} onNavigate={nav.goToPage} />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
