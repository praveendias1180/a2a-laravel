<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Agents
    |--------------------------------------------------------------------------
    |
    | Agents you mount by name with Route::a2a('/a2a', agent: 'default').
    | You can also pass the card and executor straight to Route::a2a();
    | then nothing is needed here.
    |
    | card:     a class implementing A2A\Laravel\Contracts\AgentCardProvider,
    |           or the card as a ProtoJSON array. Leave `supportedInterfaces`
    |           out and the bridge fills them in from your routes.
    | executor: a class implementing A2A\Server\AgentExecution\AgentExecutor
    |           (resolved from the container, so it can take dependencies).
    | runner:   'inline' runs the executor in the web request; 'queued' runs
    |           it in a queue job (see "queue" below). Null uses the default.
    | middleware: extra route middleware for the JSON-RPC and REST routes.
    | extended_card: optional card (same forms as `card`) returned by
    |           GetExtendedAgentCard to authenticated callers.
    |
    */

    'agents' => [
        // 'default' => [
        //     'card' => App\A2A\HelloAgentCard::class,
        //     'executor' => App\A2A\HelloExecutor::class,
        //     'runner' => 'queued',
        //     'middleware' => ['auth:sanctum'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runner
    |--------------------------------------------------------------------------
    |
    | Where executors run by default. 'inline' is simplest, but PHP-FPM can't
    | keep work going after the request ends, so anything that takes more
    | than a few seconds should use 'queued' with real queue workers.
    |
    */

    'runner' => env('A2A_RUNNER', 'inline'),

    'queue' => [
        // Null uses your default queue connection.
        'connection' => env('A2A_QUEUE_CONNECTION'),
        'name' => env('A2A_QUEUE', 'default'),

        // Seconds a job may run. Must be below the connection's retry_after.
        'timeout' => (int) env('A2A_QUEUE_TIMEOUT', 3600),

        // Seconds a web request waits for a worker to start a queued task
        // before it gives up with an error.
        'start_timeout' => (float) env('A2A_QUEUE_START_TIMEOUT', 30),

        // Cache store for the "run finished" markers (must be shared by web
        // and worker processes: redis, database, memcached or file).
        'cache_store' => env('A2A_CACHE_STORE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    |
    | How task events travel between processes (the web request streaming
    | to a client, the queue worker running the executor, other subscribers).
    |
    | 'database': polls a table on the storage connection. Works everywhere.
    | 'redis':    Redis Streams (XADD / XREAD BLOCK). Lower latency and less
    |             database load; needs phpredis or predis.
    |
    */

    'events' => [
        'driver' => env('A2A_EVENTS_DRIVER', 'database'),
        'redis_connection' => env('A2A_REDIS_CONNECTION', 'default'),
        'redis_prefix' => 'a2a:',
        // Seconds a finished task's events stay in Redis.
        'finished_ttl' => 3600,
        // Seconds an unfinished task's events stay in Redis.
        'active_ttl' => 86400,
    ],

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Tasks, the database event log and push-notification configs live on
    | this connection. Run `php artisan migrate` after publishing the
    | migrations. The table prefix is added to a2a's own table names.
    |
    */

    'storage' => [
        'connection' => env('A2A_DB_CONNECTION'),
        'table_prefix' => 'a2a_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Streaming (SSE)
    |--------------------------------------------------------------------------
    */

    'sse' => [
        // Seconds between keep-alive comments on an idle stream. PHP only
        // notices that a client hung up when it writes, so this is also how
        // quickly an abandoned stream frees its PHP-FPM worker.
        'keep_alive' => 15.0,

        // End a SubscribeToTask stream after this many idle seconds (null:
        // keep it open until the task finishes). Every open stream holds a
        // PHP-FPM worker, so a limit protects the pool.
        'max_idle' => null,

        // How long each wait for new events lasts, in seconds.
        'poll' => 0.25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Security schemes
    |--------------------------------------------------------------------------
    |
    | Route middleware for each security scheme your Agent Card declares in
    | `securityRequirements`, keyed by the scheme's name in the card. The
    | middleware is added to the JSON-RPC and REST routes automatically.
    | The public Agent Card route never gets it.
    |
    */

    'security_schemes' => [
        // 'bearer' => 'auth:sanctum',
    ],

    // The auth guard whose user owns tasks (null: the default guard). Tasks
    // are scoped to their owner: other users get "task not found".
    'guard' => null,

    // Seconds a run lease lasts if a process dies without releasing it.
    'lease_seconds' => 600,

    // Log channel for SDK errors (null: your default channel).
    'log_channel' => null,
];
