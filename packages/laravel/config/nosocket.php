<?php

return [
    'secret' => env('NOSOCKET_SECRET', env('APP_KEY')),
    'event_ttl_seconds' => (int) env('NOSOCKET_EVENT_TTL', 3600),
    'poll_limit' => (int) env('NOSOCKET_POLL_LIMIT', 100),
    'rate_limit' => (int) env('NOSOCKET_RATE_LIMIT', 120),
    'rate_window_seconds' => (int) env('NOSOCKET_RATE_WINDOW', 60),
    'token_ttl_seconds' => (int) env('NOSOCKET_TOKEN_TTL', 3600),
    'cleanup_probability' => (float) env('NOSOCKET_CLEANUP_PROBABILITY', 0),
];
