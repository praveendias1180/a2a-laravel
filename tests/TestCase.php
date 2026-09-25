<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests;

use A2A\Laravel\A2AServiceProvider;
use A2A\Laravel\Tests\Fixtures\EchoExecutor;
use A2A\Laravel\Tests\Fixtures\TestAgentCard;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [A2AServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('a2a.sse.poll', 0.02);
        $app['config']->set('a2a.sse.max_idle', 1.0);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * @param Router $router
     */
    protected function defineRoutes($router): void
    {
        $router->a2a('/a2a', agentCard: TestAgentCard::class, executor: EchoExecutor::class);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function rpc(string $method, array $params, string $path = '/a2a/jsonrpc'): TestResponse
    {
        return $this->postJson($path, ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], ['A2A-Version' => '1.0']);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function message(string $text, ?string $taskId = null): array
    {
        $message = ['messageId' => bin2hex(random_bytes(8)), 'role' => 'ROLE_USER', 'parts' => [['text' => $text]]];
        if ($taskId !== null) {
            $message['taskId'] = $taskId;
        }

        return $message;
    }

    /**
     * Parses an SSE body into its JSON data payloads.
     *
     * @return list<array<string, mixed>>
     */
    protected static function sseEvents(string $body): array
    {
        $events = [];
        foreach (preg_split("/\r?\n\r?\n/", $body) ?: [] as $frame) {
            $data = '';
            foreach (preg_split("/\r?\n/", $frame) ?: [] as $line) {
                if (str_starts_with($line, 'data:')) {
                    $data .= ltrim(substr($line, 5));
                }
            }
            if ($data !== '') {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    $events[] = $decoded;
                }
            }
        }

        return $events;
    }
}
