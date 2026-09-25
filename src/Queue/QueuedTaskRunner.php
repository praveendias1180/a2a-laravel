<?php

declare(strict_types=1);

namespace A2A\Laravel\Queue;

use A2A\Laravel\AgentDefinition;
use A2A\Server\AgentExecution\ActiveTask;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\AgentExecution\TaskRunner;
use A2A\Server\Events\QueueManager;
use A2A\Utils\Errors\InternalError;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Runs the executor in a queue job instead of the web request.
 *
 * run() dispatches a RunAgentExecutor job and then streams the task's
 * events from the QueueManager as the worker publishes them, so a
 * streaming client sees each event live and a blocking SendMessage returns
 * as soon as the task finishes or pauses. The web request can end at any
 * time (a client disconnect, `returnImmediately`): the worker keeps going.
 *
 * The job writes "waiting", "started" and "finished" markers for its run to
 * the cache ("waiting" while it waits for another run on the same task to
 * end). run() stops once the run has finished and every event is read. It
 * fails with an error when no worker picks the job up within $startTimeout
 * seconds, and it stops if a started run's lease disappears without a
 * "finished" marker (the worker died). The job writes "finished" before it
 * releases the lease, so a normal end is never mistaken for a dead worker.
 *
 * PHP-specific, like TaskRunner.
 */
final class QueuedTaskRunner implements TaskRunner
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly Cache $cache,
        private readonly QueueManager $queueManager,
        private readonly AgentDefinition $agent,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
        private readonly float $startTimeout = 30.0,
        private readonly float $pollSeconds = 0.25,
    ) {}

    public function run(ActiveTask $activeTask, RequestContext $context): \Generator
    {
        $taskId = $activeTask->taskId();
        $cursor = $this->queueManager->lastSequence($taskId);
        $runId = bin2hex(random_bytes(16));

        $job = RunAgentExecutor::fromContext($this->agent, $runId, $context);
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }
        $this->bus->dispatch($job);

        $dispatchedAt = microtime(true);
        while (true) {
            $events = $this->queueManager->read($taskId, $cursor, $this->pollSeconds);
            foreach ($events as $sequence => $published) {
                $cursor = $sequence;
                yield $published;
            }
            if ($events !== []) {
                continue;
            }

            $state = RunAgentExecutor::runState($this->cache, $runId);
            if ($state === RunAgentExecutor::FINISHED) {
                // Read once more: the last events may have landed just before the marker.
                foreach ($this->queueManager->read($taskId, $cursor) as $sequence => $published) {
                    $cursor = $sequence;
                    yield $published;
                }
                $error = RunAgentExecutor::runError($this->cache, $runId);
                if ($error !== null) {
                    throw $error;
                }

                return;
            }
            if ($state === null && microtime(true) - $dispatchedAt > $this->startTimeout) {
                throw new InternalError(sprintf('No queue worker started task %s within %.0f seconds. Is `php artisan queue:work` running?', $taskId, $this->startTimeout));
            }
            if ($state === RunAgentExecutor::STARTED && !$this->queueManager->hasActiveRunLease($taskId)
                && RunAgentExecutor::runState($this->cache, $runId) === RunAgentExecutor::STARTED) {
                // The worker holding the run is gone without finishing it.
                return;
            }
        }
    }

    /**
     * Nothing to do: the queue worker finishes the executor's work, so the
     * web request doesn't need to keep reading events after its response.
     */
    public function defer(\Closure $work): void {}

    public function runDeferred(): void {}
}
