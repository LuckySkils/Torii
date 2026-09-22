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

It can also send phone notifications when a tracked show gets a new episode and when an episode finishes downloading.

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
- **Download tracking:** Torii checks qBittorrent every minute and marks releases as downloaded, whether it queued them itself or an RSS rule did.
- **Notifications (optional):** push notifications through [ntfy](https://ntfy.sh) for "new episode out" and "episode downloaded". You can use the bundled ntfy container, your own ntfy server, or the public ntfy.sh.
- **Categories:** everything is added with a configurable qBittorrent category, so tools that organize by category (Shoko, Sonarr-style setups, custom scripts) keep working.
- **Posters** are fetched once from SubsPlease and stored in the database, with a manual reload for shows whose art appears later.
- **Release log:** every feed item is recorded with its publish time and the time Torii saw it. This is the groundwork for adaptive polling.

## How it works

```
SubsPlease RSS ──► Torii poller ──► PostgreSQL (shows, releases, polls, posters)
                        │
                        ├─► qBittorrent WebAPI: RSS rules for tracked shows
                        ├─► qBittorrent WebAPI: direct adds for known episodes and batches
                        ├─► "refresh feed now" nudge when a tracked show gets a new episode
                        ├─◄ qBittorrent WebAPI: completed torrents → "downloaded" status
                        └─► ntfy (optional): new-episode and downloaded notifications
```

qBittorrent does the downloading. Torii decides what to download and keeps qBittorrent's rules in sync.

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

On first start, Torii generates its app key, runs the database migrations, polls the feed and fetches posters on its own. The dashboard's health strip shows whether qBittorrent is reachable. For details, run `docker compose exec torii php artisan qbit:setup --self-test`.

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
- **openmediavault (compose plugin):** paste `docker-compose.yml` into the compose field and the contents of `.env` into the environment field, then use **Up**, not Restart, after changes; only Up applies new settings. The plugin saves the environment as `<name>.env` next to `<name>.yml`, so you can add `env_file: <name>.env` to the `torii` service to pass every variable automatically.

### Notifications (optional)

Torii publishes to any ntfy server. The compose file includes an optional ntfy container, locked down so that only logged-in users can read or post.

1. In `.env`, uncomment the notification block and set `NTFY_BASE_URL` to the address your phone uses to reach ntfy.
2. Start it: `docker compose up -d`. `COMPOSE_PROFILES=notifications` also starts the ntfy container.
3. Create a user (the password is your phone app login) and a token for Torii:
   ```bash
   docker compose exec ntfy ntfy user add --role=admin <name>
   docker compose exec ntfy ntfy token add <name>
   ```
4. Put the token in `NTFY_TOKEN`, then run `docker compose up -d` again.
5. In the ntfy phone app, add your server, log in, and subscribe to your topic (`NTFY_TOPIC`, default `torii`). Then use **Send test notification** on Torii's dashboard.

To use the public ntfy.sh instead, don't enable the profile. Set `NTFY_URL=https://ntfy.sh` and a long, random `NTFY_TOPIC`: on a public server, the topic name is the only protection.

Your phone receives notifications only while it can reach the ntfy server. From outside your home, that means a reverse proxy or a VPN.

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
php artisan feed:poll --force        # first poll, fills the catalog
php artisan images:fetch --missing   # posters
```

Torii needs three processes: the web server, the scheduler, and a queue worker.

```bash
php artisan serve          # web UI on http://localhost:8000
php artisan schedule:work  # polls the feed, checks downloads, reconciles rules
php artisan queue:work     # syncs rules, queues torrents, fetches posters, sends notifications
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

In qBittorrent, RSS processing and RSS auto-downloading must be enabled. `php artisan qbit:setup --fix-prefs` turns them on for you.

## Commands

With Docker, prefix these with `docker compose exec torii php artisan`. Most of them also have a button on the dashboard.

| Command | Purpose |
|---|---|
| `feed:poll {--force}` | Poll the feed (the scheduler runs it every minute; it only fetches when due) |
| `qbit:setup {--fix-prefs} {--self-test}` | Check the qBittorrent connection, feed, category and preferences |
| `qbit:reconcile` | Bring qBittorrent rules in line with tracked shows (also runs hourly) |
| `qbit:check-completed` | Mark finished downloads (also runs every minute) |
| `images:fetch {--missing} {--all}` | Fetch posters from SubsPlease |
| `shows:recompute-premiere` | Recalculate premiere dates and seasons |
| `releases:recheck-errors` | Re-check failed queue attempts against qBittorrent |

## Limitations

- Torii can only queue episodes it has seen, either in the RSS feed (a rolling window of a few days) or through SubsPlease's search API. It doesn't search other trackers.
- It relies on SubsPlease's RSS feed and its undocumented site API. If SubsPlease changes either, parts of Torii may break until they're updated.
- It is single-user with no auth, and meant for a home network.

## Roadmap

- Release statistics and adaptive polling: poll more often around the times tracked shows usually release, so new-episode notifications arrive sooner.
- Backfill of older episodes via the SubsPlease search API.
- Versioned releases (`latest` = newest release, `edge` = main branch).
- Optional metadata (English titles, descriptions) from AniList.

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

Torii is not affiliated with SubsPlease, ntfy or qBittorrent. It automates a torrent client. You are responsible for what you download and for complying with the laws of your country.

## License

[MIT](LICENSE)
