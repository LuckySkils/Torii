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

Shows are matched against AniList, so the catalog carries real cover art, genres, descriptions, episode counts and airing schedules. It can also send phone notifications when a tracked show gets a new episode and when an episode finishes downloading.

It runs on your local network. There are no accounts and no cloud.

<!-- Screenshot: add docs/screenshot.png and uncomment
<p align="center"><img src="docs/screenshot.png" alt="Torii shows grid"></p>
-->

## Features

- **Show catalog:** every show from the SubsPlease feed, with cover art, premiere season and year, search, filters and sorting, as a poster grid or a compact list.
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
- **Download tracking:** Torii checks qBittorrent every minute and marks releases as downloaded, whether it queued them itself or an RSS rule did.
- **Anime metadata (AniList):**
  - the previous, current and next season are synced weekly, and shows are matched to them automatically;
  - cover art, genres, descriptions, format, episode counts and airing schedules;
  - an **airing window** showing the previous, next and following episode at a glance, including *"Ep 13 aired, not released yet"* when an episode exists but SubsPlease hasn't posted it;
  - a **review queue** for anything the matcher wasn't sure about, with a search dialog for linking by hand;
  - a browsable `/anime` section with season, year, status, format and genre filters, where genres can be included *and* excluded;
  - matching is deliberately cautious: ambiguous candidates, and ones whose episode counts don't fit, become suggestions rather than links, and once you unlink something it stays unlinked.
- **Notifications (optional):** push notifications through [ntfy](https://ntfy.sh) for "new episode out" and "episode downloaded". Use the bundled ntfy container, your own server, or the public ntfy.sh.
- **Categories:** everything is added with a configurable qBittorrent category, so tools that organize by category (Shoko, Sonarr-style setups, custom scripts) keep working.
- **Works on a phone:** responsive throughout, installable to the home screen over HTTPS, with a light/dark theme and a density toggle for large monitors.
- **Release log:** every feed item is recorded with its publish time and the time Torii saw it, so the dashboard can show how quickly releases are spotted.

## How it works

```
SubsPlease RSS ──► Torii poller ──► PostgreSQL (shows, releases, polls, anime, covers)
                        │
                        ├─► qBittorrent WebAPI: RSS rules for tracked shows
                        ├─► qBittorrent WebAPI: direct adds for known episodes and batches
                        ├─► "refresh feed now" nudge when a tracked show gets a new episode
                        ├─◄ qBittorrent WebAPI: completed torrents → "downloaded" status
                        ├─◄ AniList GraphQL: seasons, schedules, covers, descriptions
                        └─► ntfy (optional): new-episode and downloaded notifications
```

qBittorrent does the downloading. Torii decides what to download, keeps qBittorrent's rules in sync, and enriches its catalog from AniList.

## Installation with Docker (recommended)

The image is published at `ghcr.io/luckyskils/torii` for amd64 and arm64. It contains the web UI, the queue worker and the scheduler. PostgreSQL runs as a second container from the same compose file.

1. Create a folder and download the two files into it:
   ```bash
   mkdir torii && cd torii
   curl -fsSLO https://raw.githubusercontent.com/luckyskils/torii/HEAD/docker-compose.yml
   curl -fsSL https://raw.githubusercontent.com/luckyskils/torii/HEAD/.env.example -o .env
   ```
2. Edit `.env`. At minimum, set `QBIT_URL` and `QBIT_CATEGORY`. See [Configuration](#configuration).
3. Start it:
   ```bash
   docker compose up -d
   ```
4. Open `http://<host>:8080`.

On first start Torii generates its app key, runs the migrations, and then bootstraps itself: it polls the feed, fetches posters, syncs three AniList seasons, matches shows against them and downloads cover art. That work is queued, so the UI is usable straight away and the dashboard shows the progress. Bootstrap steps added in later releases run once on the next start, so an existing install fills in new data by itself.

The dashboard's health strip shows whether qBittorrent is reachable. For details, run `docker compose exec torii php artisan qbit:setup --self-test`.

**Updating:**
```bash
docker compose pull && docker compose up -d
```
Migrations run automatically on start.

**Data:** everything Torii stores (database, app key, ntfy data) lives in `TORII_DATA_DIR` (default `./data`). Back up that folder, or dump the database:
```bash
docker compose exec -T db pg_dump -U torii torii | gzip > torii-$(date +%F).sql.gz
```

### Notes for Docker setups

- **qBittorrent on the same host:** set `QBIT_URL` to the host's LAN IP, not `localhost`. Inside a container, `localhost` is the container itself.
- **qBittorrent's auth bypass:** requests from Torii come from Docker's internal network, not your LAN. Either set `QBIT_USERNAME`/`QBIT_PASSWORD`, or add `172.16.0.0/12` (Docker's default range) to qBittorrent's "Bypass authentication for clients in whitelisted IP subnets".
- **Adding settings:** a value in `.env` reaches the container only if `docker-compose.yml` lists it under `torii → environment:`. The shipped file lists the common settings. For others (e.g. `NOTIFY_REPACKS`), add a line there as well.
- **Behind a reverse proxy:** set `APP_URL` to the external HTTPS address. Torii trusts proxy headers, so assets and redirects follow it. Over HTTPS the browser also offers to install Torii to the home screen.
- **openmediavault (compose plugin):** paste `docker-compose.yml` into the compose field and the contents of `.env` into the environment field, then use **Up**, not Restart, after changes; only Up applies new settings. The plugin saves the environment as `<name>.env` next to `<name>.yml`, so you can add `env_file: <name>.env` to the `torii` service to pass every variable automatically.

### Notifications (optional)

Torii publishes to any ntfy server. The compose file includes an optional ntfy container, locked down so that only logged-in users can read or post.

1. In `.env`, uncomment the notification block and set `NTFY_BASE_URL` to the address your phone uses to reach ntfy.
2. Start it: `docker compose up -d`. `COMPOSE_PROFILES=notifications` also starts the ntfy container.
3. Create a user (its password is your phone app login) and a token for Torii:
   ```bash
   docker compose exec ntfy ntfy user add --role=admin <name>
   docker compose exec ntfy ntfy token add <name>
   ```
4. Put the token in `NTFY_TOKEN`, then run `docker compose up -d` again.
5. In the ntfy phone app, add your server, log in, and subscribe to your topic (`NTFY_TOPIC`, default `torii`). Then use **Send test notification** on Torii's dashboard.

To use the public ntfy.sh instead, don't enable the profile. Set `NTFY_URL=https://ntfy.sh` and a long, random `NTFY_TOPIC`: on a public server, the topic name is the only protection.

Your phone receives notifications only while it can reach the ntfy server. From outside your home, that means a reverse proxy or a VPN.

### MCP server (optional)

Torii can act as an [MCP](https://modelcontextprotocol.io) server, so an LLM client can browse your catalog, schedule and shows, suggest what to watch from your own data, and (if you allow it) track shows and queue downloads. It runs inside the same container and is off by default.

1. In `.env`, set `MCP_ENABLED=true` and a long random `MCP_TOKEN` (e.g. `openssl rand -hex 32`), then `docker compose up -d`.
2. Add it to your client. Claude Code:
   ```bash
   claude mcp add --transport http torii http://192.168.1.10:8080/mcp --header "Authorization: Bearer <MCP_TOKEN>"
   ```
   Claude Desktop accepts the same URL and header in its MCP configuration, or can bridge to it with `mcp-remote`.

Tools: `search_anime`, `get_anime`, `list_schedule`, `list_shows`, `get_show`, `tracked_summary`, `suggest_link` and `suggest_anime` are read-only. `track_show`, `untrack_show`, `queue_missing` and `download_release` exist only with `MCP_ALLOW_WRITES=true`; each call is logged.

**Its own port.** By default MCP shares the UI's port at `/mcp`. To separate them, set `MCP_PORT` (e.g. `7099`): the container then starts a second listener on that port that answers only `/mcp`, and `/mcp` disappears from the UI's port. Docker publishes that port only when you opt in with the second compose file, by adding this to `.env`:

```env
MCP_PORT=7099
# On Windows, use ; instead of : between the files
COMPOSE_FILE=docker-compose.yml:docker-compose.mcp.yml
```

or by running `docker compose -f docker-compose.yml -f docker-compose.mcp.yml up -d`. With `MCP_PORT` empty, no extra port is published. The container refuses to start if `MCP_PORT` equals the main port (`80` inside, `TORII_PORT` outside).

**Exposure.** Treat it like the UI: LAN-only by default. For remote use, put the MCP port behind a reverse proxy with TLS and set `MCP_TOKEN`; that's enough for Claude Code and Claude Desktop. claude.ai's custom connectors currently accept only OAuth, which Torii doesn't implement, so they can't connect. Exposing the endpoint publicly with `MCP_ALLOW_WRITES=true` means an LLM client can start downloads on your machine; leave writes off unless you want that.

The endpoint speaks MCP protocol `2026-07-28` and, for clients that still use the `initialize` handshake, `2025-11-25` and `2025-06-18`. It is rate-limited to 120 requests a minute, and list tools return at most `MCP_MAX_RESULTS` rows.

## Installation without Docker

Requirements:

- PHP **8.4** with the `pdo_pgsql`, `curl`, `mbstring`, `intl` and `zip` extensions
- Composer 2
- Node.js 20+ and npm, to build the frontend
- PostgreSQL 14+
- qBittorrent **5.x** with the WebUI enabled. Tested on 5.0.3 (WebAPI 2.11.2); 4.6+ should work.

```bash
git clone https://github.com/luckyskils/torii.git
cd torii

composer install
npm ci && npm run build

cp .env.example .env
php artisan key:generate
```

The `.env.example` in the repo is written for Docker. For a manual install, also set the database connection (`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) and `QUEUE_CONNECTION=database`. Then:

```bash
php artisan migrate
php artisan qbit:setup --self-test   # checks qBittorrent, adds the feed and category
php artisan torii:bootstrap          # first poll, posters, AniList seasons, matching, covers
```

`torii:bootstrap` only queues the work, so a queue worker has to be running for it to finish.

Torii needs three processes: the web server, the scheduler, and a queue worker.

```bash
php artisan serve          # web UI on http://localhost:8000
php artisan schedule:work  # polls the feed, checks downloads, reconciles rules, syncs AniList
php artisan queue:work     # syncs rules, queues torrents, fetches images, sends notifications
```

For a permanent setup, run these under systemd or Supervisor. Restart `queue:work` after every update, because workers keep the old code in memory.

> **Security:** Torii has no authentication by design. Only expose it on a trusted network, or behind a reverse proxy with its own auth.

## Configuration

| Variable | Default | Description |
|---|---|---|
| `QBIT_URL` | | qBittorrent WebUI URL, e.g. `http://192.168.1.10:8080` |
| `QBIT_USERNAME` / `QBIT_PASSWORD` | | Leave both empty if qBittorrent bypasses auth for Torii's IP |
| `QBIT_CATEGORY` | `anime` | Category for all downloads; the save path comes from the category. Case-sensitive. |
| `QBIT_FEED_PATH` | `SubsPlease 1080p` | Name of the feed inside qBittorrent's RSS tree |
| `QBIT_RULE_PREFIX` | `[ST] ` | Prefix of rules Torii owns; rules without it are never touched |
| `QBIT_TAG` | `subtracker` | Tag added to torrents Torii queues directly |
| `FEED_URL` | `https://subsplease.org/rss/?r=1080` | SubsPlease feed to track |
| `FEED_POLL_BASE_MINUTES` | `15` | Normal poll interval |
| `FEED_PUBDATE_OFFSET_MINUTES` | `-420` | Correction for the feed's timestamps. SubsPlease labels them `+0000`, but their clock runs 7 hours behind UTC, so Torii shifts every release time 7 hours later to get true UTC. If their offset follows US daylight saving, this will need to be `-480` from November; the dashboard's median polling delay drifting by about an hour is the sign. After changing it, run `releases:fix-published`. |
| `METADATA_PROVIDER` | `anilist` | Metadata source. Providers sit behind an interface, so others can be added. |
| `ANILIST_REQUESTS_PER_MINUTE` | `25` | Self-imposed throttle. AniList documents 90/min but has long run degraded at 30/min, so Torii stays below that. |
| `APP_URL` | `http://localhost:8080` | Address you open Torii at |
| `TORII_PORT` | `8080` | Docker: host port for the web UI |
| `TORII_DATA_DIR` | `./data` | Docker: host folder for the database, app key and ntfy data |
| `DB_PASSWORD` | `torii` | Database password (the bundled database isn't exposed outside Docker) |
| `NTFY_URL` | *(empty = off)* | ntfy server Torii publishes to, e.g. `http://ntfy:80` or `https://ntfy.sh` |
| `NTFY_TOPIC` | `torii` | Topic to publish to |
| `NTFY_TOKEN` | | ntfy access token, if the server requires login |
| `NOTIFY_NEW_EPISODE` | `true` | Notify when a tracked show gets a new episode |
| `NOTIFY_DOWNLOADED` | `true` | Notify when an episode finishes downloading |
| `NOTIFY_REPACKS` | `false` | Also notify for `v2`+ re-releases |
| `NOTIFY_CLICK_URL` | `APP_URL` | Where tapping a notification opens Torii |
| `MCP_ENABLED` | `false` | Serve the MCP endpoint. Off: `/mcp` doesn't exist. |
| `MCP_PORT` | *(empty)* | Empty: MCP at `/mcp` on the UI's port. Set: a separate listener on this port that serves only `/mcp`. Docker publishes it only with `docker-compose.mcp.yml`. Must differ from the main port. |
| `MCP_TOKEN` | *(empty)* | Bearer token; when set, every MCP request needs `Authorization: Bearer <token>` |
| `MCP_ALLOW_WRITES` | `false` | Also offer the tools that track/untrack shows and queue downloads. Off: they aren't listed at all. |
| `MCP_MAX_RESULTS` | `50` | Hard cap on rows any MCP tool returns |

In qBittorrent, RSS processing and RSS auto-downloading must be enabled. `php artisan qbit:setup --fix-prefs` turns them on for you.

## Commands

With Docker, prefix these with `docker compose exec torii php artisan`. Most of them also have a button in the UI.

| Command | Purpose |
|---|---|
| `torii:bootstrap {--list} {--force=key}` | Run the one-time setup tasks (feed poll, posters, AniList seasons, matching, covers). Runs at container start; each task runs once, and failed ones retry on the next start. |
| `feed:poll {--force}` | Poll the feed (the scheduler runs it every minute; it only fetches when due) |
| `qbit:setup {--fix-prefs} {--self-test}` | Check the qBittorrent connection, feed, category and preferences |
| `qbit:reconcile` | Bring qBittorrent rules in line with tracked shows (also runs hourly) |
| `qbit:check-completed` | Mark finished downloads (also runs every minute) |
| `anime:sync-season {--weekly}` | Sync AniList seasons and re-run matching (`--weekly`: previous, current and next season plus linked anime; runs weekly) |
| `anime:sync-airings` | Refresh airing schedules and episode counts (runs daily) |
| `anime:match {--dry-run}` | Match shows against stored anime without syncing |
| `anime:fetch-images {--missing}` | Download AniList cover art |
| `images:fetch {--missing} {--all}` | Fetch posters from SubsPlease |
| `shows:recompute-premiere` | Recalculate premiere dates and seasons |
| `releases:recheck-errors` | Re-check failed queue attempts against qBittorrent |
| `releases:fix-published` | Recompute every release time from the raw feed value and `FEED_PUBDATE_OFFSET_MINUTES` (safe to re-run) |

## Limitations

- Torii can only queue episodes it has seen in the RSS feed, which is a rolling window of a few days. It doesn't search other trackers.
- It relies on SubsPlease's RSS feed and its undocumented site API. If SubsPlease changes either, parts of Torii may break until they're updated.
- Metadata depends on AniList, polled under a conservative rate limit. Torii works normally with the metadata layer empty or AniList unreachable.
- Matching is name-based. Split-cour titles (e.g. "4th Season Part 1 & 2") and shows numbered on from an earlier season usually need linking by hand.
- It is single-user with no auth, and meant for a home network. The optional MCP endpoint has a bearer token, but no OAuth.

## Roadmap

- A schedule view: what airs today and this week, built on the stored airing times.
- Backfill of older episodes via the SubsPlease search API.
- Additional release sources beyond SubsPlease.
- Versioned releases (`latest` = newest release, `edge` = main branch).

## Tech stack

Laravel 12 · PHP 8.4 · PostgreSQL · Inertia 2 · React 19 · TypeScript · Tailwind CSS 4 · shadcn/ui · Pest · FrankenPHP · Docker

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

Torii is not affiliated with SubsPlease, AniList, ntfy or qBittorrent. It automates a torrent client. You are responsible for what you download and for complying with the laws of your country.

## License

[MIT](LICENSE)
