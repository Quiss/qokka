<?php

return [
    'content' => [
        'duplicate_lookback_days' => (int) env('CONTENT_DUPLICATE_LOOKBACK_DAYS', 14),
        'duplicate_history_limit' => (int) env('CONTENT_DUPLICATE_HISTORY_LIMIT', 80),
        'retention_days' => (int) env('CONTENT_RETENTION_DAYS', 14),
    ],

    'sources' => [
        'connect_timeout' => 5,
        'request_timeout' => 30,
        'media_timeout' => 30,
        'remote_media_max_bytes' => 10 * 1024 * 1024,
    ],
];
