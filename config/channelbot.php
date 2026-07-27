<?php

return [
    'content' => [
        'duplicate_lookback_days' => (int) env('CONTENT_DUPLICATE_LOOKBACK_DAYS', 14),
        'duplicate_history_limit' => (int) env('CONTENT_DUPLICATE_HISTORY_LIMIT', 80),
        'retention_days' => (int) env('CONTENT_RETENTION_DAYS', 14),
    ],
];
