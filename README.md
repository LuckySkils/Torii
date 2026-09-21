<p align="center">
  <img src="public/logo-mark.svg" width="96" alt="Torii logo">
</p>

<h1 align="center">Torii</h1>

<p align="center">
  A self-hosted SubsPlease show tracker that manages qBittorrent for you.
</p>

---

Torii watches the SubsPlease RSS feed, builds a catalog of every show it sees, and lets you pick the ones you want. For each show you track, it:

- queues the episodes it already knows about in qBittorrent;
- creates an RSS auto-download rule in qBittorrent for future episodes;
- downloads a single batch torrent instead, when a finished season is available as a batch.

It runs on your local network. There are no accounts and no cloud.

<!-- Screenshot: add docs/screenshot.png and uncomment
<p align="center"><img src="docs/screenshot.png" alt="Torii shows grid"></p>
-->

## Features

- **Show catalog:** every show from the SubsPlease feed, with posters, premiere season and year, search, filters and sorting.
- **One-click tracking:**
  - tracking a show queues every episode Torii knows about (the highest version per episode, e.g. `v2` over the original);
  - it then creates a qBittorrent RSS rule for new episodes;
  - untracking disables the rule without deleting download history.
- **Batch aware:**
  - a show with a batch torrent is downloaded as one batch, with no per-episode duplicates and no RSS rule;
  - if the show is still airing past the batch, Torii also queues the later episodes and creates the rule.
- **Safe with qBittorrent:**
  - Torii only manages rules with its own name prefix;
  - it checks existing torrents before adding;
  - it preserves qBittorrent's smart episode filter history;
  - a reconcile job fixes drift.
- **Instant downloads:** when Torii sees a new episode of a tracked show, it tells qBittorrent to refresh the feed right away, instead of waiting for qBittorrent's own RSS timer.
- **Categories:** everything is added with a configurable qBittorrent category, so tools that organize by category (Shoko, Sonarr-style setups, custom scripts) keep working.
- **Posters** are fetched once from SubsPlease and stored in the database, with a manual reload for shows whose art appears later.
- **Release log:** every feed item is recorded with its publish time and the time Torii saw it. This is the groundwork for adaptive polling.

## How it works

```
SubsPlease RSS ──► Torii poller ──► PostgreSQL (shows, releases, polls, posters)
                        │
                        ├─► qBittorrent WebAPI: RSS rules for tracked shows
                        ├─► qBittorrent WebAPI: direct adds for known episodes and batches
                        └─► "refresh feed now" nudge when a tracked show gets a new episode
```

qBittorrent does the downloading. Torii decides what to download and keeps qBittorrent's rules in sync.

## Requirements

- PHP **8.4** with the `pdo_pgsql`, `curl`, `mbstring` and `gd` (or equivalent image support) extensions
- Composer 2
- Node.js 20+ and npm, to build the frontend
- PostgreSQL 14+
- qBittorrent **5.x** with the WebUI enabled. Tested on 5.0.3 (WebAPI 2.11.2); 4.6+ should work.

## Installation

```bash
git clone https://github.com/LuckySkils/torii.git
cd torii

composer install
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

Edit `.env` (see [Configuration](#configuration)), then:

```bash
php artisan migrate
php artisan qbit:setup --self-test   # checks qBittorrent, adds the feed and category
php artisan feed:poll --force        # first poll, fills the catalog
php artisan images:fetch --missing   # posters for existing shows
```

## Running

Torii needs three processes: the web server, the scheduler, and a queue worker.

```bash
php artisan serve          # web UI on http://localhost:8000
php artisan schedule:work  # polls the feed, reconciles qBittorrent rules
php artisan queue:work     # syncs rules, queues torrents, fetches posters
```

For a permanent setup, run these under systemd, Supervisor or Docker, and put the app behind your usual reverse proxy. Restart `queue:work` after every update, because workers keep the old code in memory.

> **Security:** Torii has no authentication by design. Only expose it on a trusted network, or behind a reverse proxy with its own auth.

## Configuration

All settings live in `.env`.

| Variable | Default | Description |
|---|---|---|
| `FEED_URL` | `https://subsplease.org/rss/?r=1080` | SubsPlease feed to track |
| `FEED_POLL_BASE_MINUTES` | `15` | Normal poll interval |
| `QBIT_URL` | | qBittorrent WebUI URL, e.g. `http://192.168.1.10:8080` |
| `QBIT_USERNAME` / `QBIT_PASSWORD` | | Leave both empty if qBittorrent bypasses auth for Torii's IP |
| `QBIT_FEED_PATH` | `SubsPlease 1080p` | Name of the feed inside qBittorrent's RSS tree |
| `QBIT_CATEGORY` | `Anime` | Category for all downloads; the save path comes from the category. Case-sensitive. |
| `QBIT_RULE_PREFIX` | `[ST] ` | Prefix of rules Torii owns; rules without it are never touched |
| `QBIT_TAG` | `subtracker` | Tag added to torrents Torii queues directly |
| `DB_*` | | PostgreSQL connection |

In qBittorrent, RSS processing and RSS auto-downloading must be enabled. `php artisan qbit:setup --fix-prefs` turns them on for you.

## Commands

| Command | Purpose |
|---|---|
| `feed:poll {--force}` | Poll the feed (the scheduler runs it every minute; it only fetches when due) |
| `qbit:setup {--fix-prefs} {--self-test}` | Check the qBittorrent connection, feed, category and preferences |
| `qbit:reconcile` | Bring qBittorrent rules in line with tracked shows (also runs hourly) |
| `images:fetch {--missing} {--all}` | Fetch posters from SubsPlease |
| `shows:recompute-premiere` | Recalculate premiere dates and seasons |
| `releases:recheck-errors` | Re-check failed queue attempts against qBittorrent |

## Limitations

- Torii can only queue episodes it has seen, either in the RSS feed (a rolling window of a few days) or through SubsPlease's search API. It doesn't search other trackers.
- It relies on SubsPlease's RSS feed and its undocumented site API. If SubsPlease changes either, parts of Torii may break until they're updated.
- It is single-user with no auth, and meant for a home network.

## Roadmap

- Release statistics and adaptive polling: poll more often around the times tracked shows usually release.
- Backfill of older episodes via the SubsPlease search API.
- Optional metadata (English titles, descriptions) from AniList.
- Docker image.

## Tech stack

Laravel 12 · PHP 8.4 · PostgreSQL · Inertia 2 · React 19 · TypeScript · Tailwind CSS 4 · shadcn/ui · Pest

## Development

```bash
composer run dev   # server, queue, logs and Vite together (if your setup supports it)
php artisan test
./vendor/bin/pint
npm run lint && npx tsc --noEmit
```

Tests never touch the network; all external HTTP is faked against real saved responses in `tests/Fixtures`. A separate test database is expected; see `phpunit.xml`.

The project was built with LLM assistance.

## Disclaimer

Torii is not affiliated with SubsPlease or qBittorrent. It automates a torrent client. You are responsible for what you download and for complying with the laws of your country.

## License

[MIT](LICENSE)
