import { BatchHint } from '@/components/subtracker/batch-hint';
import { DeleteRuleDialog } from '@/components/subtracker/delete-rule-dialog';
import { LatestEpisodeLabel } from '@/components/subtracker/latest-episode-label';
import { MatchesDialog } from '@/components/subtracker/matches-dialog';
import { RuleBadge } from '@/components/subtracker/rule-badge';
import { SeasonLabel } from '@/components/subtracker/season-label';
import { ShowPoster } from '@/components/subtracker/show-poster';
import { TrackSwitch } from '@/components/subtracker/track-switch';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type RuleState, type ShowSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { MoreVertical } from 'lucide-react';
import { useState } from 'react';

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

interface ShowCardProps {
    show: ShowSummary;
    selected: boolean;
    onToggleSelect: (checked: boolean) => void;
}

export function ShowCard({ show, selected, onToggleSelect }: ShowCardProps) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [matchesOpen, setMatchesOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const canDeleteRule = DELETABLE_RULE_STATES.includes(show.ruleState);

    function openMatches() {
        setMenuOpen(false);
        setMatchesOpen(true);
    }

    function openDelete() {
        setMenuOpen(false);
        setDeleteOpen(true);
    }

    return (
        <div className="group flex flex-col gap-2 rounded-lg border p-2">
            <div className="relative">
                <Link href={`/shows/${show.id}`}>
                    <ShowPoster
                        imageUrl={show.imageUrl}
                        imageStatus={show.imageStatus}
                        imageWidth={show.imageWidth}
                        imageHeight={show.imageHeight}
                        name={show.name}
                        className="w-full"
                    />
                </Link>

                <div
                    className={
                        'absolute top-1 left-1 transition-opacity ' +
                        (selected ? 'opacity-100' : 'opacity-0 group-focus-within:opacity-100 group-hover:opacity-100')
                    }
                >
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(checked) => onToggleSelect(checked === true)}
                        aria-label={`Select ${show.name}`}
                        className="border-white/70 bg-background/80"
                    />
                </div>

                <div className="absolute top-1 right-1 opacity-0 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100">
                    <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className="flex size-7 items-center justify-center rounded-md bg-background/80 hover:bg-background"
                                aria-label={`Actions for ${show.name}`}
                            >
                                <MoreVertical className="size-4" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem
                                onSelect={(e) => {
                                    e.preventDefault();
                                    openMatches();
                                }}
                            >
                                Preview matches
                            </DropdownMenuItem>
                            {canDeleteRule && (
                                <DropdownMenuItem
                                    className="text-destructive"
                                    onSelect={(e) => {
                                        e.preventDefault();
                                        openDelete();
                                    }}
                                >
                                    Delete rule
                                </DropdownMenuItem>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <Tooltip>
                <TooltipTrigger asChild>
                    <Link href={`/shows/${show.id}`} className="line-clamp-2 text-sm leading-tight font-medium hover:underline">
                        {show.name}
                    </Link>
                </TooltipTrigger>
                <TooltipContent>{show.name}</TooltipContent>
            </Tooltip>

            <div className="flex items-center justify-between gap-2 text-xs text-muted-foreground">
                <LatestEpisodeLabel latest={show.latest} />
                <SeasonLabel season={show.season} seasonYear={show.seasonYear} premiereSource={show.premiereSource} />
            </div>

            <div className="flex items-center gap-1.5">
                <TrackSwitch
                    showId={show.id}
                    showName={show.name}
                    tracked={show.isTracked}
                    downloadableCount={show.downloadableCount}
                    hasBatch={show.hasBatch}
                />
                <RuleBadge trackingMode={show.trackingMode} state={show.ruleState} error={show.ruleError} />
                {show.hasBatch && !show.isTracked && <BatchHint />}
            </div>

            <MatchesDialog showId={show.id} showName={show.name} open={matchesOpen} onOpenChange={setMatchesOpen} />
            <DeleteRuleDialog showId={show.id} showName={show.name} open={deleteOpen} onOpenChange={setDeleteOpen} />
        </div>
    );
}
