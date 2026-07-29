<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'url' => env('OPENROUTER_URL', 'https://openrouter.ai/api/v1'),
        'analysis_model' => env('OPENROUTER_ANALYSIS_MODEL', 'google/gemini-3-flash-preview'),
        'rewrite_model' => env('OPENROUTER_REWRITE_MODEL', 'google/gemini-3.6-flash'),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 300),
        'connect_timeout' => (int) env('OPENROUTER_CONNECT_TIMEOUT', 10),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'bot_api_url' => env('TELEGRAM_BOT_API_URL', 'https://api.telegram.org'),
        'messenger_base_url' => env('MESSENGER_BASE_URL', 'https://t.me'),
        'bot_api_timeout' => (int) env('TELEGRAM_BOT_API_TIMEOUT', 300),
        'bot_api_connect_timeout' => (int) env('TELEGRAM_BOT_API_CONNECT_TIMEOUT', 10),
        'publishing_stale_after' => (int) env('TELEGRAM_PUBLISHING_STALE_AFTER', 600),
        'operations' => [
            'chat_id' => env('TELEGRAM_OPERATIONS_CHAT_ID'),
            'topics' => [
                'content_plans' => (int) env('TELEGRAM_OPERATIONS_CONTENT_PLANS_TOPIC_ID', 4),
                'failures' => (int) env('TELEGRAM_OPERATIONS_FAILURES_TOPIC_ID', 5),
            ],
            'timeout' => (int) env('TELEGRAM_OPERATIONS_TIMEOUT', 10),
            'connect_timeout' => (int) env('TELEGRAM_OPERATIONS_CONNECT_TIMEOUT', 3),
        ],
        'api_id' => env('TELEGRAM_API_ID'),
        'api_hash' => env('TELEGRAM_API_HASH'),
        'bridge_secret' => env('TELEGRAM_BRIDGE_SECRET'),
        'bridge_url' => env('TELEGRAM_BRIDGE_URL', env('APP_URL').'/api/internal/telegram/updates'),
        'subscriptions_url' => env('TELEGRAM_SUBSCRIPTIONS_URL', env('APP_URL').'/api/internal/telegram/subscriptions'),
        'media_max_bytes' => (int) env('TELEGRAM_MEDIA_MAX_BYTES', 300 * 1024 * 1024),
        'database_max_connections' => (int) env('TELEGRAM_DATABASE_MAX_CONNECTIONS', 20),
        'database_idle_timeout' => (int) env('TELEGRAM_DATABASE_IDLE_TIMEOUT', 300),
        'download_parallel_chunks' => (int) env('TELEGRAM_DOWNLOAD_PARALLEL_CHUNKS', 4),
        'rpc_drop_timeout' => (int) env('TELEGRAM_RPC_DROP_TIMEOUT', 60),
        'owner_heartbeat_seconds' => (int) env('TELEGRAM_OWNER_HEARTBEAT_SECONDS', 15),
        'owner_lease_ttl_seconds' => (int) env('TELEGRAM_OWNER_LEASE_TTL_SECONDS', 45),
        'media_retry_window_seconds' => (int) env('TELEGRAM_MEDIA_RETRY_WINDOW_SECONDS', 43200),
        'media_lock_seconds' => (int) env('TELEGRAM_MEDIA_LOCK_SECONDS', 420),
        'coordination_cache_store' => env('TELEGRAM_COORDINATION_CACHE_STORE', 'redis'),
        'socks5' => [
            'host' => env('TELEGRAM_SOCKS5_HOST'),
            'port' => (int) env('TELEGRAM_SOCKS5_PORT', 1080),
            'username' => env('TELEGRAM_SOCKS5_USERNAME'),
            'password' => env('TELEGRAM_SOCKS5_PASSWORD'),
            'proxy_only' => (bool) env('TELEGRAM_SOCKS5_PROXY_ONLY', true),
            'https_transport' => (bool) env('TELEGRAM_SOCKS5_HTTPS_TRANSPORT', true),
        ],
    ],

];
