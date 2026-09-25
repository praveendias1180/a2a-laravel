<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Queue\RunAgentExecutor;
use A2A\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * The queued runner on the `sync` queue driver: the job runs during
 * dispatch, and the web side reads its events from the event log.
 */
final class QueuedRunnerSyncTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('a2a.runner', 'queued');
    }

    public function testSendMessageRunsOnTheQueue(): void
    {
        $this->rpc('SendMessage', ['message' => self::message('queued')])
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED')
            ->assertJsonPath('result.task.artifacts.0.parts.0.text', 'echo: queued');
    }

    public function testStreamingOnTheQueue(): void
    {
        $events = self::sseEvents($this->rpc('SendStreamingMessage', ['message' => self::message('queued stream')])->streamedContent());

        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], array_map(static fn(array $e): string => (string) array_key_first(is_array($e['result'] ?? null) ? $e['result'] : []), $events));
    }

    public function testMessageRepliesAndPausedTasksOnTheQueue(): void
    {
        $this->rpc('SendMessage', ['message' => self::message('message: q')])->assertJsonPath('result.message.parts.0.text', 'reply: q');
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_INPUT_REQUIRED')
            ->json('result.task.id');
        $this->rpc('SendMessage', ['message' => self::message('go on', $taskId)])->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
    }

    public function testExecutorErrorsFailTheCallLikeTheInlineRunner(): void
    {
        config()->set('a2a.runner', 'inline');
        $inline = $this->rpc('SendMessage', ['message' => self::message('bad')])->json('error');
        config()->set('a2a.runner', 'queued');
        $queued = $this->rpc('SendMessage', ['message' => self::message('bad')])->json('error');

        self::assertIsArray($inline);
        self::assertSame(-32006, $inline['code'] ?? null);
        self::assertSame($inline, $queued);
    }

    public function testNonA2AErrorsStayHiddenOnTheQueueToo(): void
    {
        $response = $this->rpc('SendMessage', ['message' => self::message('fail')]);

        $response->assertJsonPath('error.code', -32603)->assertJsonPath('error.message', 'Internal error');
        self::assertStringNotContainsString('boom', (string) $response->getContent());
    }

    public function testTheJobPayloadCarriesTheCallerButNotTheirCredentials(): void
    {
        Queue::fake();
        config()->set('a2a.queue.start_timeout', 0.1);

        $this->postJson('/a2a/jsonrpc', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'SendMessage', 'params' => ['message' => self::message('hi')]], [
            'A2A-Version' => '1.0',
            'Authorization' => 'Bearer top-secret',
            'X-Trace' => 'abc',
        ]);

        Queue::assertPushed(RunAgentExecutor::class, static function (RunAgentExecutor $job): bool {
            $payload = serialize($job);

            return !str_contains($payload, 'top-secret')
                && ($job->caller['headers']['x-trace'] ?? null) === 'abc'
                && $job->caller['authenticated'] === false;
        });
    }

    public function testNoWorkerMeansAClearErrorAfterTheStartTimeout(): void
    {
        Queue::fake();
        config()->set('a2a.queue.start_timeout', 0.1);

        $response = $this->rpc('SendMessage', ['message' => self::message('nobody home')]);

        $response->assertJsonPath('error.code', -32603);
    }

    public function testAJobForACancelledTaskDoesNotRunTheExecutor(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');
        $this->rpc('CancelTask', ['id' => $taskId])->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');

        $job = new RunAgentExecutor(
            agent: ['name' => 'default', 'card' => \A2A\Laravel\Tests\Fixtures\TestAgentCard::class, 'executor' => \A2A\Laravel\Tests\Fixtures\EchoExecutor::class],
            runId: 'late-run',
            taskId: $taskId,
            contextId: '',
            request: json_encode(['message' => self::message('too late', $taskId)], JSON_THROW_ON_ERROR),
            caller: ['user' => '', 'authenticated' => false, 'tenant' => '', 'extensions' => [], 'headers' => []],
        );
        $job->handle(app(\A2A\Laravel\A2AManager::class));

        $this->rpc('GetTask', ['id' => $taskId])->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');
        self::assertSame(RunAgentExecutor::FINISHED, RunAgentExecutor::runState(app(\A2A\Laravel\A2AManager::class)->runCache(), 'late-run'));
    }
}
