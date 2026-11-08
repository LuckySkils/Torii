<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImageStatus;
use App\Enums\PremiereSource;
use App\Enums\ReleaseSource;
use App\Enums\RuleState;
use App\Enums\Season;
use App\Enums\TrackingMode;
use App\Services\Releases\ReleaseTitleParser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Show extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'name',
        'slug',
        'first_seen_at',
        'last_seen_at',
        'latest_episode',
        'is_tracked',
        'tracking_mode',
        'tracked_at',
        'rule_state',
        'rule_synced_at',
        'rule_error',
        'image_status',
        'image_checked_at',
        'image_error',
        'premiered_at',
        'premiere_source',
        'season',
        'season_year',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_tracked' => 'boolean',
            'tracking_mode' => TrackingMode::class,
            'tracked_at' => 'datetime',
            'rule_state' => RuleState::class,
            'rule_synced_at' => 'datetime',
            'image_status' => ImageStatus::class,
            'image_checked_at' => 'datetime',
            'premiered_at' => 'datetime',
            'premiere_source' => PremiereSource::class,
            'season' => Season::class,
            'season_year' => 'integer',
        ];
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class);
    }

    public function latestRelease(): HasOne
    {
        return $this->hasOne(Release::class)->latestOfMany('published_at');
    }

    public function image(): HasOne
    {
        return $this->hasOne(ShowImage::class);
    }

    public function animeLink(): HasOne
    {
        return $this->hasOne(ShowAnimeLink::class);
    }

    public function animeSuggestions(): HasMany
    {
        return $this->hasMany(ShowAnimeSuggestion::class);
    }

    public function animeRejections(): HasMany
    {
        return $this->hasMany(ShowAnimeRejection::class);
    }

    /** The one reason an untrackable show gives, the same for every show (§16). */
    public const BLOCKED_REASON = "No SubsPlease releases: Torii's download rule only follows SubsPlease's feed.";

    /**
     * Adds what trackability() needs as one `exists` subquery (no N+1 in lists):
     * whether the show has a SubsPlease release.
     *
     * @param  Builder<Show>  $query
     */
    public function scopeWithTrackability(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select($query->qualifyColumn('*'));
        }

        $query->withExists(['releases as has_subsplease_release' => fn (Builder $releases) => self::subsPleaseReleases($releases)]);
    }

    /**
     * Whether the show can be tracked (§16): Torii's download rule follows
     * SubsPlease's feed, so a show needs at least one SubsPlease release (feed or
     * import). A tracked show always counts as trackable, so it's never stranded.
     * Uses withTrackability()'s columns when loaded, else queries.
     *
     * @return array{trackable: bool, reason: string|null}
     */
    public function trackability(): array
    {
        if ($this->is_tracked) {
            return ['trackable' => true, 'reason' => null];
        }

        $hasSubsPlease = array_key_exists('has_subsplease_release', $this->attributes)
            ? (bool) $this->attributes['has_subsplease_release']
            : self::subsPleaseReleases($this->releases()->getQuery())->exists();

        return $hasSubsPlease
            ? ['trackable' => true, 'reason' => null]
            : ['trackable' => false, 'reason' => self::blockedReason()];
    }

    public static function blockedReason(): string
    {
        return self::BLOCKED_REASON;
    }

    /**
     * Releases that count as SubsPlease's: the group says so, or it came from the
     * SubsPlease feed (rows stored before `release_group` existed included).
     *
     * @param  Builder<Release>  $releases
     * @return Builder<Release>
     */
    private static function subsPleaseReleases(Builder $releases): Builder
    {
        return $releases->where(fn (Builder $q) => $q
            ->where('release_group', ReleaseTitleParser::SUBSPLEASE)
            ->orWhere('source', ReleaseSource::SubsPleaseRss));
    }
}
