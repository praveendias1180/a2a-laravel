<?php

declare(strict_types=1);

namespace A2A\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Laravel entry point for the A2A SDK.
 *
 * Routes, config, stores and the queued task runner land here in phase 4
 * (see the roadmap in the repository README).
 */
final class A2AServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
