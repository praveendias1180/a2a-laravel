<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class ConsoleTest extends TestCase
{
    public function testMakeExecutorWritesAnExecutorClass(): void
    {
        $path = app_path('A2A/HelloExecutor.php');
        try {
            $this->artisan('a2a:make-executor', ['name' => 'Hello'])->assertSuccessful();

            self::assertFileExists($path);
            $code = (string) file_get_contents($path);
            self::assertStringContainsString('namespace App\A2A;', $code);
            self::assertStringContainsString('class HelloExecutor implements AgentExecutor', $code);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir(dirname($path)) && count(scandir(dirname($path)) ?: []) === 2) {
                rmdir(dirname($path));
            }
        }
    }

    public function testCardPrintsAndValidatesTheCard(): void
    {
        // The card JSON is one write, so one expectation covers it.
        $this->artisan('a2a:card', ['agent' => 'default'])
            ->expectsOutputToContain('"url": "http://localhost/a2a/jsonrpc"')
            ->expectsOutputToContain('The card is valid.')
            ->assertSuccessful();
    }

    public function testCardFailsForAnInvalidCard(): void
    {
        config()->set('a2a.agents.broken', ['card' => ['name' => 'Broken'], 'executor' => \A2A\Laravel\Tests\Fixtures\EchoExecutor::class]);

        $this->artisan('a2a:card', ['agent' => 'broken'])->assertFailed();
    }

    public function testPruneDeletesOldFinishedTasksOnly(): void
    {
        $done = (string) $this->rpc('SendMessage', ['message' => self::message('old')])->json('result.task.id');
        $open = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');
        DB::table('a2a_tasks')->update(['status_timestamp' => 1]);

        $this->artisan('a2a:prune', ['--days' => 1])->expectsOutputToContain('Deleted 1 finished task(s)')->assertSuccessful();

        $this->rpc('GetTask', ['id' => $done])->assertJsonPath('error.code', -32001);
        $this->rpc('GetTask', ['id' => $open])->assertJsonPath('result.id', $open);
    }

    public function testTckNeedsATckCheckout(): void
    {
        $this->artisan('a2a:tck')->expectsOutputToContain('--tck-dir')->assertFailed();
    }

    public function testConfigAndMigrationsArePublishable(): void
    {
        $config = config_path('a2a.php');
        try {
            $this->artisan('vendor:publish', ['--tag' => 'a2a-config'])->assertSuccessful();
            self::assertFileExists($config);
            self::assertIsArray(require $config);
        } finally {
            if (is_file($config)) {
                unlink($config);
            }
        }

        $before = glob(database_path('migrations/*a2a_tables.php')) ?: [];
        try {
            $this->artisan('vendor:publish', ['--tag' => 'a2a-migrations'])->assertSuccessful();
            $after = glob(database_path('migrations/*a2a_tables.php')) ?: [];
            self::assertCount(count($before) + 1, $after);
        } finally {
            foreach (array_diff(glob(database_path('migrations/*a2a_tables.php')) ?: [], $before) as $file) {
                unlink($file);
            }
        }
    }
}
