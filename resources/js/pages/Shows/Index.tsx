import { BatchHint } from '@/components/subtracker/batch-hint';
import { BulkBar } from '@/components/subtracker/bulk-bar';
import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { LatestEpisodeLabel } from '@/components/subtracker/latest-episode-label';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { PosterHoverPreview } from '@/components/subtracker/poster-hover-preview';
import { RelativeTime } from '@/components/subtracker/relative-time';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { SeasonLabel } from '@/components/subtracker/season-label';
import { ShowCard } from '@/components/subtracker/show-card';
import { ShowsToolbar } from '@/components/subtracker/shows-toolbar';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Pagination, PaginationContent, PaginationItem, PaginationLink } from '@/components/ui/pagination';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useRecentActivity } from '@/hooks/use-recent-activity';
import { useShowsView } from '@/hooks/use-shows-view';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { type RuleState, type ShowsIndexProps } from '@/types/subtracker';
import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Eye, LayoutGrid, List, Trash2 } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Shows', href: '/shows' }];

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

export default function ShowsIndex({ shows, filters, filterOptions }: ShowsIndexProps) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [view, setView] = useShowsView();

    const recentActivity = useRecentActivity();
    const hasPendingRow = shows.data.some((show) => show.ruleState === 'pending' || show.imageStatus === 'pending');
    usePoll(hasPendingRow || recentActivity ? 5000 : 60000, { only: ['shows'] });

    useEffect(() => {
        setSelectedIds([]);
    }, [filters.q, filters.tracked, filters.sort, filters.season, filters.year, shows.meta.current_page]);

    const pageIds = shows.data.map((show) => show.id);
    const allSelected = pageIds.length > 0 && pageIds.every((id) => selectedIds.includes(id));

    function toggleSelectAll(checked: boolean) {
        setSelectedIds(checked ? pageIds : []);
    }

    function toggleSelect(id: number, checked: boolean) {
        setSelectedIds((current) => (checked ? [...current, id] : current.filter((selectedId) => selectedId !== id)));
    }

    function goTo(url: string | null) {
        if (url) {
            router.get(url, {}, { preserveState: true, preserveScroll: true });
        }
    }

    function clearFilters() {
        router.get(
            '/shows',
            { q: '', tracked: 'all', sort: filters.sort, season: '', year: '' },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const isFiltered = filters.q !== '' || filters.tracked !== 'all' || filters.season !== null || filters.year !== null;
    const isEmptyCatalog = shows.meta.total === 0 && !isFiltered;
    const isEmptyResults = shows.data.length === 0 && !isEmptyCatalog;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Shows" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <ShowsToolbar filters={filters} years={filterOptions.years} />
                    <ToggleGroup
                        type="single"
                        value={view}
                        onValueChange={(value) => value && setView(value as 'grid' | 'list')}
                        aria-label="Shows view"
                    >
                        <ToggleGroupItem value="grid" aria-label="Grid view">
                            <LayoutGrid className="size-4" />
                        </ToggleGroupItem>
                        <ToggleGroupItem value="list" aria-label="List view">
                            <List className="size-4" />
                        </ToggleGroupItem>
                    </ToggleGroup>
                </div>

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
                        <p>No shows match your filters.</p>
                        <Button variant="outline" size="sm" onClick={clearFilters}>
                            Clear filters
                        </Button>
                    </div>
                )}

                {shows.data.length > 0 && view === 'grid' && (
                    <div className="grid gap-4" style={{ gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))' }}>
                        {shows.data.map((show) => (
                            <ShowCard
                                key={show.id}
                                show={show}
                                selected={selectedIds.includes(show.id)}
                                onToggleSelect={(checked) => toggleSelect(show.id, checked)}
                            />
                        ))}
                    </div>
                )}

                {shows.data.length > 0 && view === 'list' && (
                    <div className="overflow-x-auto rounded-xl border">
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
                                    <TableHead>Name</TableHead>
                                    <TableHead>Latest</TableHead>
                                    <TableHead>Last seen</TableHead>
                                    <TableHead>Track</TableHead>
                                    <TableHead>Rule</TableHead>
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
                                            <PosterHoverPreview
                                                imageUrl={show.imageUrl}
                                                imageStatus={show.imageStatus}
                                                imageWidth={show.imageWidth}
                                                imageHeight={show.imageHeight}
                                                name={show.name}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col gap-0.5">
                                                <div className="flex items-center gap-1.5">
                                                    <Link href={`/shows/${show.id}`} className="font-medium hover:underline">
                                                        {show.name}
                                                    </Link>
                                                    {show.hasBatch && !show.isTracked && <BatchHint />}
                                                </div>
                                                <SeasonLabel
                                                    season={show.season}
                                                    seasonYear={show.seasonYear}
                                                    premiereSource={show.premiereSource}
                                                    className="text-xs text-muted-foreground"
                                                />
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
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8"
                                                            aria-label={`Preview matches for ${show.name}`}
                                                        >
                                                            <Eye className="size-4" />
                                                        </Button>
                                                    }
                                                />
                                                {DELETABLE_RULE_STATES.includes(show.ruleState) && (
                                                    <DeleteRuleDialog
                                                        showId={show.id}
                                                        showName={show.name}
                                                        trigger={
                                                            <Button
                                                                variant="ghost"
                                                                size="icon"
                                                                className="size-8 text-destructive hover:text-destructive"
                                                                aria-label={`Delete rule for ${show.name}`}
                                                            >
                                                                <Trash2 className="size-4" />
                                                            </Button>
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
                )}

                {shows.data.length > 0 && (
                    <div className="sticky bottom-0 -mx-4 mt-auto border-t bg-background/95 px-4 py-2 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                        <Pagination>
                            <PaginationContent>
                                {shows.meta.links.map((link, index) => (
                                    <PaginationItem key={index}>
                                        {link.url === null ? (
                                            <span
                                                className="flex h-9 min-w-9 items-center justify-center px-3 text-sm text-muted-foreground opacity-50"
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        ) : (
                                            <PaginationLink
                                                href={link.url}
                                                isActive={link.active}
                                                onClick={(e) => {
                                                    e.preventDefault();
                                                    goTo(link.url);
                                                }}
                                                dangerouslySetInnerHTML={{ __html: link.label }}
                                            />
                                        )}
                                    </PaginationItem>
                                ))}
                            </PaginationContent>
                        </Pagination>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
