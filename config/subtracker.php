<?php

declare(strict_types=1);

return [

    'feed' => [
        'url' => env('FEED_URL', 'https://subsplease.org/rss/?r=1080'),
        'poll_base_minutes' => (int) env('FEED_POLL_BASE_MINUTES', 15),
        'poll_hot_minutes' => (int) env('FEED_POLL_HOT_MINUTES', 2),
        'hot_window_minutes' => (int) env('FEED_HOT_WINDOW_MINUTES', 45),
        // SubsPlease labels pubDate "+0000" while its clock actually runs this many
        // minutes off UTC; published_at = parsed pubDate minus this offset.
        'pubdate_offset_minutes' => (int) env('FEED_PUBDATE_OFFSET_MINUTES', -420),
    ],

    'qbittorrent' => [
        'url' => env('QBIT_URL', 'http://192.168.x.x:8080'),
        'username' => env('QBIT_USERNAME', ''),
        'password' => env('QBIT_PASSWORD', ''),
        'mode' => env('QBIT_MODE', 'rules'),
        'feed_path' => env('QBIT_FEED_PATH', 'SubsPlease 1080p'),
        'category' => env('QBIT_CATEGORY', 'anime'),
        'rule_prefix' => env('QBIT_RULE_PREFIX', '[ST] '),
        'tag' => env('QBIT_TAG', 'subtracker'),
    ],

    'metadata' => [
        // Key of the active MetadataProvider (see AppServiceProvider).
        'provider' => env('METADATA_PROVIDER', 'anilist'),
        'anilist' => [
            'url' => 'https://graphql.anilist.co',
            // AniList documents 90/min but has long run degraded at 30/min; stay below that.
            'requests_per_minute' => (int) env('ANILIST_REQUESTS_PER_MINUTE', 25),
            // Longest a queued job waits on the throttle before releasing itself back to the
            // queue instead; keeps jobs under the worker's 60s timeout.
            'max_wait_seconds' => 30,
        ],
    ],

    // Delivery reconciler (§17): optional, needs Shoko + Shokofin + Jellyfin.
    'reconciler' => [
        'enabled' => filter_var(env('RECONCILER_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Dry run unless explicitly switched off: an empty or misspelled value stays dry,
        // since the test stand talks to the real Shoko and Jellyfin.
        // (env() turns "false" into a boolean false; any other false-ish word is matched as text.)
        'dry_run' => ($dryRun = env('RECONCILER_DRY_RUN', 'true')) !== false
            && ! in_array(strtolower(trim((string) $dryRun)), ['false', '0', 'off', 'no'], true),
        'shoko_url' => rtrim((string) env('SHOKO_URL', ''), '/'),
        'shoko_api_key' => (string) env('SHOKO_API_KEY', ''),
        'jellyfin_url' => rtrim((string) env('JELLYFIN_URL', ''), '/'),
        'jellyfin_api_key' => (string) env('JELLYFIN_API_KEY', ''),
        'jellyfin_anime_library_id' => (string) env('JELLYFIN_ANIME_LIBRARY_ID', ''),
        // Stable across restarts, so Jellyfin sees one device, not a new session each time.
        'jellyfin_device_id' => ((string) env('JELLYFIN_DEVICE_ID', '')) ?: 'torii-reconciler',
        // Seconds after the trigger at which each check attempt runs.
        'backoff' => array_values(array_map('intval', array_filter(array_map('trim', explode(',', (string) env('RECONCILER_BACKOFF', '3,7,15'))), 'is_numeric'))) ?: [3, 7, 15],
        'library_refresh_timeout' => (int) env('RECONCILER_LIBRARY_REFRESH_TIMEOUT', 300),
        'listener_lifetime' => (int) env('RECONCILER_LISTENER_LIFETIME', 86400),
        'notify_on_fix' => filter_var(env('RECONCILER_NOTIFY_ON_FIX', true), FILTER_VALIDATE_BOOL),
        'event_retention_days' => 14,
        'shoko_ping_seconds' => 15,
        'jellyfin_keepalive_seconds' => 30,
        // A heartbeat older than this means the listener is gone.
        'heartbeat_stale_seconds' => 120,
        'recent_episodes_limit' => 50,
    ],

    // Import from a Nyaa RSS link (§16).
    'nyaa' => [
        // Announce URLs added to every magnet built from a Nyaa item, in this order.
        'trackers' => array_values(array_filter(array_map('trim', explode(',', (string) env('NYAA_TRACKERS', ''))))) ?: [
            'http://nyaa.tracker.wf:7777/announce',
            'udp://open.stealth.si:80/announce',
            'udp://tracker.opentrackr.org:1337/announce',
            'udp://exodus.desync.com:6969/announce',
            'udp://tracker.torrent.eu.org:451/announce',
        ],
        'timeout_seconds' => 15,
        // A full Nyaa RSS page is about 75 items, well under 1 MB.
        'max_bytes' => 4 * 1024 * 1024,
        // Nyaa returns one page; a feed this long may have been cut off.
        'full_page_items' => 75,
        'preview_ttl_minutes' => 15,
        'previews_per_minute' => 10,
    ],

    // MCP server (§15). Off by default; with MCP_PORT set it gets its own listener.
    'mcp' => [
        'enabled' => filter_var(env('MCP_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Empty: served at POST /mcp on the main port. Set: a second listener on this port serves only /mcp.
        'port' => ($port = trim((string) env('MCP_PORT', ''))) === '' ? null : (int) $port,
        // When false, write tools are not registered at all (absent from tools/list).
        'allow_writes' => filter_var(env('MCP_ALLOW_WRITES', false), FILTER_VALIDATE_BOOL),
        // When set, every request needs `Authorization: Bearer <token>`.
        'token' => (string) env('MCP_TOKEN', ''),
        // Hard cap on any list tool's page size.
        'max_results' => max(1, (int) env('MCP_MAX_RESULTS', 50)),
        'rate_limit_per_minute' => 120,
    ],

    'notifications' => [
        'enabled' => env('NTFY_URL', '') !== '' && env('NTFY_TOPIC', 'torii') !== '',
        'ntfy_url' => env('NTFY_URL', ''),
        'ntfy_topic' => env('NTFY_TOPIC', 'torii'),
        'ntfy_token' => env('NTFY_TOKEN', ''),
        'notify_new_episode' => (bool) env('NOTIFY_NEW_EPISODE', true),
        'notify_downloaded' => (bool) env('NOTIFY_DOWNLOADED', true),
        'notify_repacks' => (bool) env('NOTIFY_REPACKS', false),
        'click_url' => env('NOTIFY_CLICK_URL', ''),
    ],

];
