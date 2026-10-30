<?php

return [
    'enabled' => env('MYSQL_CONCURRENCY_ENABLED', false),
    'host' => env('MYSQL_CONCURRENCY_HOST', ''),
    'port' => env('MYSQL_CONCURRENCY_PORT', ''),
    'database' => env('MYSQL_CONCURRENCY_DATABASE', ''),
    'username' => env('MYSQL_CONCURRENCY_USERNAME', ''),
    'instance_id' => env('MYSQL_CONCURRENCY_INSTANCE_ID', ''),
    'lock_timeout_seconds' => (int) env('MYSQL_CONCURRENCY_LOCK_TIMEOUT_SECONDS', 5),
];
