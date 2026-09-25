# A2A for Laravel

Laravel bridge for the [A2A PHP SDK](https://github.com/praveendias1180/a2a-php).

> **Read-only split.** Development happens in [`praveendias1180/a2a-php`](https://github.com/praveendias1180/a2a-php) under `packages/laravel`. Open issues and PRs there.

**Status:** skeleton only. The bridge is phase 4 of the roadmap. It will add:

- `Route::a2a()` to mount JSON-RPC, REST and the Agent Card
- a queued task runner and Redis-backed event streaming, so long tasks run on queue workers while web requests stream updates over SSE
- Eloquent task and push-config stores with migrations
- guard-based auth and artisan commands (`a2a:make-executor`, `a2a:card`)
