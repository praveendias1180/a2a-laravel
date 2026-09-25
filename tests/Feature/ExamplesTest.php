<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Client\ClientConfig;
use A2A\Laravel\Tests\Support\InProcessPsr18Client;
use A2A\Laravel\Tests\TestCase;
use App\A2A\CallAgent;
use Illuminate\Contracts\Http\Kernel;

/**
 * The Laravel examples in examples/laravel/ (shown in the docs) work as
 * written.
 */
final class ExamplesTest extends TestCase
{
    private const EXAMPLES = __DIR__ . '/../../../../examples/laravel';

    protected function defineRoutes($router): void
    {
        require_once self::EXAMPLES . '/HelloAgentCard.php';
        require_once self::EXAMPLES . '/HelloExecutor.php';
        require self::EXAMPLES . '/routes.php';
    }

    public function testTheExampleAgentAnswers(): void
    {
        $this->rpc('SendMessage', ['message' => self::message('world')])
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED')
            ->assertJsonPath('result.task.artifacts.0.parts.0.text', 'Hello, world');
        $this->get('/.well-known/agent-card.json')->assertJsonPath('name', 'Hello Agent');
    }

    public function testTheCallAgentExampleTalksToAnAgent(): void
    {
        require_once self::EXAMPLES . '/CallAgent.php';
        $this->app->instance(ClientConfig::class, new ClientConfig(httpClient: new InProcessPsr18Client($this->app->make(Kernel::class))));

        self::assertSame('Hello, Laravel', (new CallAgent())->ask('http://localhost', 'Laravel'));
    }

    public function testTheCallAgentExampleWorksWithoutStreamingToo(): void
    {
        require_once self::EXAMPLES . '/CallAgent.php';
        $this->app->instance(ClientConfig::class, new ClientConfig(streaming: false, httpClient: new InProcessPsr18Client($this->app->make(Kernel::class))));

        self::assertSame('Hello, blocking', (new CallAgent())->ask('http://localhost', 'blocking'));
    }
}
