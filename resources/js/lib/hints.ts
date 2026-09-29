/**
 * Every action and column explanation in one place, so the wording can be
 * reviewed and corrected in one pass. One plain sentence each: what happens,
 * and whether it changes anything outside Torii.
 */

export const ACTION_HINTS = {
    forcePoll: 'Asks SubsPlease for new episodes right now, instead of waiting for the next scheduled check.',
    reconcile: "Rewrites Torii's rules in qBittorrent to match the shows you track.",
    fetchMissingImages:
        'Downloads posters from SubsPlease for every show that has none yet or whose last attempt failed, a few seconds apart in the background.',
    sendTestNotification: 'Sends a test message to your ntfy topic, so you can check that notifications reach your phone.',
    queueMissing:
        "Sends every release of this show that hasn't gone to qBittorrent yet (one per episode, plus any batches) to qBittorrent for download.",
    queueMissingNone: 'Nothing to send: every release of this show has already gone to qBittorrent.',
    previewMatches: "Asks qBittorrent which items in its SubsPlease feed this show's rule matches right now. Nothing is downloaded.",
    deleteRule:
        "Removes this show's rule from qBittorrent entirely. qBittorrent also forgets which episodes the rule already downloaded, so a new rule later can download them again.",
    reloadImage: "Downloads this show's poster from SubsPlease again and replaces the stored one.",
    changeLink: 'Picks a different AniList entry for this show; titles, episode counts and air dates switch to it. Nothing in qBittorrent changes.',
    unlink: "Removes the AniList link. Automatic matching won't pick the same entry again; you can still link it by hand.",
    download: 'Sends this release to qBittorrent now, whether or not the show is tracked.',
    retryDownload: 'Sends this release to qBittorrent again after the last attempt failed.',
    copyLink: "Copies the release's magnet link.",
    track: 'Tracked shows get a rule in qBittorrent that downloads new episodes automatically; turning it off disables the rule.',
} as const;

export const COLUMN_HINTS = {
    published: 'When SubsPlease published the release, according to its feed.',
    firstSeen: 'When Torii first saw the release in the feed.',
    delay: 'How long Torii took to notice the release: first seen minus published. Usually less than your poll interval.',
    status: "queued: Torii sent it to qBittorrent. in qBit: qBittorrent already had it. downloaded: qBittorrent finished it. error: sending failed (tap for why). —: not sent, usually because the show isn't tracked.",
    showName:
        'SubsPlease\'s name for the show. A blue "aired, not released yet" line means AniList says the episode has aired but SubsPlease hasn\'t released it.',
    latest: 'The newest episode Torii has seen in the feed for this show.',
    lastSeen: 'When this show last appeared in the SubsPlease feed.',
    track: 'Whether Torii downloads new episodes of this show automatically.',
    rule: "The show's download rule in qBittorrent. active: in place and enabled. syncing: being written. disabled: kept but switched off. error: qBittorrent refused it (tap for why). batch: downloaded as one batch, no rule. —: no rule.",
    airingTime: 'When the episode airs, shown in your own timezone. "estimated" means the metadata provider only guessed the time.',
    airingState: 'A tick means it has aired; otherwise how long until it airs. "next" marks the next episode due.',
} as const;

/** Schedule entry badges. */
export const SCHEDULE_HINTS = {
    new: 'First episode, or a series that started within the last two weeks.',
    linked: 'Linked to a SubsPlease show in Torii. Click to open it.',
    tracked: 'You track this show: new episodes download automatically.',
    downloaded: 'Torii already has this episode downloaded.',
    released: "SubsPlease has released this episode, but Torii hasn't downloaded it yet.",
    waiting: "Aired, but SubsPlease hasn't released it yet.",
    adult: 'Marked as adult on AniList.',
    estimate: 'The air time is an estimate from the metadata provider, not a confirmed slot.',
} as const;
