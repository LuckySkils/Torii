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

];
