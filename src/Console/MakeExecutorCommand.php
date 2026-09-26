<?php

declare(strict_types=1);

namespace A2A\Laravel\Console;

use Illuminate\Console\GeneratorCommand;

/**
 * php artisan a2a:make-executor Hello  →  app/A2A/HelloExecutor.php
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class MakeExecutorCommand extends GeneratorCommand
{
    protected $name = 'a2a:make-executor';

    protected $description = 'Create an A2A agent executor class';

    protected $type = 'Executor';

    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/a2a-executor.stub');

        return is_file($published) ? $published : __DIR__ . '/../../stubs/executor.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace . '\A2A';
    }

    protected function getNameInput(): string
    {
        $argument = $this->argument('name');
        $name = trim(is_string($argument) ? $argument : '');

        return str_ends_with($name, 'Executor') ? $name : $name . 'Executor';
    }
}
