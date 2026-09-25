# A2A for Laravel

Laravel bridge for the [A2A PHP SDK](https://github.com/praveendias1180/a2a-php): serve and call [A2A (Agent2Agent)](https://a2a-protocol.org/latest/specification/) agents from Laravel 12 and 13 (Laravel 11 is allowed but untested; Composer blocks its releases for security advisories).

> **Read-only split.** Development happens in [`praveendias1180/a2a-php`](https://github.com/praveendias1180/a2a-php) under `packages/laravel`. Open issues and PRs there.

📖 **Guide: <https://praveendias1180.github.io/a2a-php/guides/laravel/>**

```bash
composer require praveendias1180/a2a-laravel
php artisan vendor:publish --tag=a2a-migrations && php artisan migrate
php artisan a2a:make-executor Hello
```

```php
// routes/api.php
Route::a2a('/a2a', agentCard: HelloAgentCard::class, executor: HelloExecutor::class)
    ->middleware('auth:sanctum');
```

- **One route macro** mounts the Agent Card (`/.well-known/agent-card.json`), JSON-RPC and HTTP+JSON, and fills the card's interface URLs in from your routes. `route:cache` safe.
- **Queued execution** (`A2A_RUNNER=queued`): executors run on your queue workers (Horizon, supervisor), and the web request streams their events live over SSE. Tasks outlive the request that started them; cancel reaches the worker.
- **Event log on Redis Streams or your database.** Tasks, events and encrypted push-notification configs are stored on your DB connection, scoped to the authenticated user.
- **Client:** `A2A::client('https://agent.example.com')->sendMessage(...)`.
- **Artisan:** `a2a:make-executor`, `a2a:card`, `a2a:prune`, `a2a:tck`.

Proven with the official [A2A TCK](https://github.com/a2aproject/a2a-tck): a Laravel app on PHP-FPM + nginx with the queued runner passes the MUST level ([conformance](https://praveendias1180.github.io/a2a-php/reference/conformance/)).

Apache-2.0.
