<?php

/**
 * Runs `php artisan queue:work` for the cross-process tests, in a fresh
 * Testbench app configured like the test process (see SharedEnvironment).
 *
 *     A2A_SHARED_ENV='{"database":...}' php worker.php
 */

declare(strict_types=1);

require __DIR__ . '/../../../../vendor/autoload.php';

use A2A\Laravel\A2AServiceProvider;
use A2A\Laravel\Tests\Support\SharedEnvironment;
use Illuminate\Contracts\Console\Kernel;
use Orchestra\Testbench\Foundation\Application;

$settings = json_decode((string) getenv('A2A_SHARED_ENV'), true, 512, JSON_THROW_ON_ERROR);
$app = Application::create(options: ['extra' => ['providers' => [A2AServiceProvider::class]]]);
SharedEnvironment::apply($app['config'], $settings);

exit($app->make(Kernel::class)->call('queue:work', [
    '--sleep' => 0.05,
    '--max-time' => 90,
    '--timeout' => 60,
    '--tries' => 1,
]));
