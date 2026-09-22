<?php

declare(strict_types=1);

return [

    'feed' => [
        'url' => env('FEED_URL', 'https://subsplease.org/rss/?r=1080'),
        'poll_base_minutes' => (int) env('FEED_POLL_BASE_MINUTES', 15),
        'poll_hot_minutes' => (int) env('FEED_POLL_HOT_MINUTES', 2),
        'hot_window_minutes' => (int) env('FEED_HOT_WINDOW_MINUTES', 45),
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
