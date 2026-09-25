<?php

declare(strict_types=1);

namespace A2A\Laravel;

use A2A\Laravel\Console\CardCommand;
use A2A\Laravel\Console\MakeExecutorCommand;
use A2A\Laravel\Console\PruneCommand;
use A2A\Laravel\Console\TckCommand;
use A2A\Laravel\Routing\A2ARoutes;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

/**
 * Laravel entry point for the A2A SDK (auto-discovered).
 *
 * - `Route::a2a('/a2a', agentCard: ..., executor: ...)` mounts an agent.
 * - `php artisan vendor:publish --tag=a2a-config|a2a-migrations`
 * - Commands: a2a:make-executor, a2a:card, a2a:prune, a2a:tck.
 */
final class A2AServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/a2a.php', 'a2a');
        $this->app->singleton(A2AManager::class);
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/a2a.php' => config_path('a2a.php')], 'a2a-config');
        $this->publishesMigrations([__DIR__ . '/../database/migrations' => database_path('migrations')], 'a2a-migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([MakeExecutorCommand::class, CardCommand::class, PruneCommand::class, TckCommand::class]);
        }

        Router::macro('a2a', /** @param string|array<array-key, mixed>|null $agentCard */ function (
            string $prefix = '/a2a',
            string|array|null $agentCard = null,
            ?string $executor = null,
            ?string $agent = null,
            ?string $runner = null,
            bool $wellKnown = true,
        ): A2ARoutes {
            /** @var Router $this */
            return A2ARoutes::register($this, app(A2AManager::class), $prefix, $agentCard, $executor, $agent, $runner, $wellKnown);
        });
    }
}
