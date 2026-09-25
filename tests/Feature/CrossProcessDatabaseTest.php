<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

/**
 * Queued runner + database queue + database event log, with a real worker.
 */
final class CrossProcessDatabaseTest extends CrossProcessTestCase
{
    protected function eventsDriver(): string
    {
        return 'database';
    }

    public function testTheExecutorRunsInTheWorkerProcess(): void
    {
        $this->startWorker();

        $response = $this->rpc('SendMessage', ['message' => self::message('pid')]);

        $response->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
        $text = (string) $response->json('result.task.artifacts.0.parts.0.text');
        self::assertMatchesRegularExpression('/^pid: \d+$/', $text, $this->workerOutput());
        self::assertNotSame('pid: ' . getmypid(), $text);
    }

    public function testStreamingFromAWorker(): void
    {
        $this->startWorker();

        $events = self::sseEvents($this->rpc('SendStreamingMessage', ['message' => self::message('slow 1')])->streamedContent());
        $kinds = array_map(static fn(array $e): string => (string) array_key_first(is_array($e['result'] ?? null) ? $e['result'] : []), $events);

        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'artifactUpdate', 'statusUpdate'], $kinds, $this->workerOutput());
        self::assertSame('TASK_STATE_COMPLETED', $events[4]['result']['statusUpdate']['status']['state'] ?? null);
    }

    public function testTheTaskOutlivesTheRequestThatStartedIt(): void
    {
        $this->startWorker();

        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('slow 2'), 'configuration' => ['returnImmediately' => true]])
            ->json('result.task.id');
        self::assertNotSame('', $taskId, $this->workerOutput());

        $state = $this->waitForState($taskId, 'TASK_STATE_COMPLETED', 15.0);
        self::assertSame('TASK_STATE_COMPLETED', $state, $this->workerOutput());
    }

    public function testCancelFromAnotherRequestStopsTheWorker(): void
    {
        $this->startWorker();

        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('slow 20'), 'configuration' => ['returnImmediately' => true]])
            ->json('result.task.id');
        $this->waitForState($taskId, 'TASK_STATE_WORKING', 10.0);

        $started = microtime(true);
        $this->rpc('CancelTask', ['id' => $taskId])->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');

        self::assertLessThan(5.0, microtime(true) - $started, 'The worker should stop at its next event, not run the full 20 s.');
        self::assertSame('TASK_STATE_CANCELED', $this->waitForState($taskId, 'TASK_STATE_CANCELED', 5.0), $this->workerOutput());
    }

    private function waitForState(string $taskId, string $state, float $timeout): ?string
    {
        $deadline = microtime(true) + $timeout;
        $current = null;
        while (microtime(true) < $deadline) {
            $current = $this->rpc('GetTask', ['id' => $taskId])->json('result.status.state');
            if ($current === $state) {
                return $state;
            }
            usleep(100_000);
        }

        return is_string($current) ? $current : null;
    }
}
