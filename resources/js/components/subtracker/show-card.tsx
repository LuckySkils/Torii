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
import { cn, TOUCH_TARGET_MD } from '@/lib/utils';
import { type RuleState, type ShowSummary } from '@/types/subtracker';
import { Link } from '@inertiajs/react';
import { MoreVertical } from 'lucide-react';
import { useState } from 'react';

const DELETABLE_RULE_STATES: RuleState[] = ['synced', 'disabled', 'error'];

interface ShowCardProps {
    show: ShowSummary;
    selected: boolean;
    onToggleSelect: (checked: boolean) => void;
    /** Touch selection mode: the whole card toggles selection instead of navigating, and the checkbox stays visible. */
    selectionMode?: boolean;
}

export function ShowCard({ show, selected, onToggleSelect, selectionMode = false }: ShowCardProps) {
    const [menuOpen, setMenuOpen] = useState(false);
    const [matchesOpen, setMatchesOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const canDeleteRule = DELETABLE_RULE_STATES.includes(show.ruleState);
    const checkboxVisible = selected || selectionMode;

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
                {selectionMode ? (
                    <button
                        type="button"
                        className="block w-full cursor-pointer bg-transparent p-0"
                        onClick={() => onToggleSelect(!selected)}
                        aria-label={`${selected ? 'Deselect' : 'Select'} ${show.name}`}
                    >
                        <ShowPoster
                            imageUrl={show.imageUrl}
                            imageStatus={show.imageStatus}
                            imageWidth={show.imageWidth}
                            imageHeight={show.imageHeight}
                            name={show.name}
                            className="w-full"
                        />
                    </button>
                ) : (
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
                )}

                <div
                    className={cn(
                        'absolute top-1 left-1 transition-opacity',
                        checkboxVisible ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 group-focus-within:opacity-100',
                    )}
                >
                    <Checkbox
                        checked={selected}
                        onCheckedChange={(checked) => onToggleSelect(checked === true)}
                        aria-label={`Select ${show.name}`}
                        className="size-5 border-white/70 bg-background/80"
                    />
                </div>

                <div className="absolute top-1 right-1 opacity-0 transition-opacity group-hover:opacity-100 group-focus-within:opacity-100 [@media(hover:none)]:opacity-100">
                    <DropdownMenu open={menuOpen} onOpenChange={setMenuOpen}>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                className={cn('flex size-8 items-center justify-center rounded-md bg-background/80 hover:bg-background', TOUCH_TARGET_MD)}
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

            {selectionMode ? (
                <button
                    type="button"
                    className="cursor-pointer bg-transparent p-0 text-left text-sm leading-tight font-medium"
                    onClick={() => onToggleSelect(!selected)}
                >
                    <span className="line-clamp-2">{show.name}</span>
                </button>
            ) : (
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Link href={`/shows/${show.id}`} className="line-clamp-2 text-sm leading-tight font-medium hover:underline">
                            {show.name}
                        </Link>
                    </TooltipTrigger>
                    <TooltipContent>{show.name}</TooltipContent>
                </Tooltip>
            )}

            <div className="flex flex-col gap-0.5 text-xs text-muted-foreground">
                <div className="truncate">
                    <LatestEpisodeLabel latest={show.latest} />
                </div>
                <div className="truncate">
                    <SeasonLabel season={show.season} seasonYear={show.seasonYear} premiereSource={show.premiereSource} />
                </div>
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
