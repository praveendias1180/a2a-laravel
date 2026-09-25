<?php

declare(strict_types=1);

namespace A2A\Laravel\Queue;

use A2A\Laravel\A2AManager;
use A2A\Laravel\AgentDefinition;
use A2A\Laravel\Auth\SerializedUser;
use A2A\Server\AgentExecution\ActiveTaskRegistry;
use A2A\Server\AgentExecution\RequestContext;
use A2A\Server\AgentExecution\SimpleRequestContextBuilder;
use A2A\Server\ServerCallContext;
use A2A\Types\SendMessageRequest;
use A2A\Utils\Errors\A2AError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one A2A request's executor on a queue worker.
 *
 * The web request that dispatched it streams the events this job publishes
 * (see QueuedTaskRunner). Only one run per task executes at a time: the job
 * holds the task's run lease in the QueueManager while execute() runs, and a
 * second job for the same task (a follow-up message) waits for it. That is
 * why the job is not ShouldBeUnique: uniqueness would drop the follow-up
 * instead of running it next.
 *
 * Executor failures are not retried: ActiveTask has already marked the
 * task FAILED and told every subscriber, so the job logs and ends. It also
 * records the error for the run, and the web request re-throws it after the
 * last event, so a queued run fails the call exactly like an inline run
 * (e.g. InvalidAgentResponseError comes back as JSON-RPC -32006).
 */
final class RunAgentExecutor implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public const WAITING = 'waiting';
    public const STARTED = 'started';
    public const FINISHED = 'finished';

    /** Headers never copied into the job payload (it is stored in the queue backend). */
    private const PRIVATE_HEADERS = ['authorization', 'cookie', 'proxy-authorization', 'x-api-key', 'x-csrf-token', 'x-xsrf-token'];

    public int $tries = 1;

    public int $timeout;

    /**
     * @param array<string, mixed>                                                                                            $agent  AgentDefinition::toArray()
     * @param array{user: string, authenticated: bool, tenant: string, extensions: list<string>, headers: array<string, string>} $caller
     */
    public function __construct(
        public readonly array $agent,
        public readonly string $runId,
        public readonly string $taskId,
        public readonly string $contextId,
        public readonly string $request,
        public readonly array $caller,
    ) {
        $timeout = config('a2a.queue.timeout', 3600);
        $this->timeout = is_numeric($timeout) ? (int) $timeout : 3600;
    }

    public static function fromContext(AgentDefinition $agent, string $runId, RequestContext $context): self
    {
        $call = $context->callContext();
        $headers = [];
        foreach ($call->headers() ?? [] as $name => $value) {
            if (!in_array(strtolower($name), self::PRIVATE_HEADERS, true)) {
                $headers[$name] = $value;
            }
        }

        return new self(
            agent: $agent->toArray(),
            runId: $runId,
            taskId: (string) $context->taskId(),
            contextId: (string) $context->contextId(),
            request: ($context->request() ?? new SendMessageRequest())->serializeToJsonString(),
            caller: [
                'user' => $call->user->userName(),
                'authenticated' => $call->user->isAuthenticated(),
                'tenant' => $call->tenant,
                'extensions' => $call->requestedExtensions,
                'headers' => $headers,
            ],
        );
    }

    public static function runState(Cache $cache, string $runId): ?string
    {
        $state = $cache->get(self::cacheKey($runId));

        return is_string($state) ? $state : null;
    }

    /**
     * The error a finished run ended with, rebuilt for the web request: the
     * same A2AError class and message, or (for any other exception, whose
     * details stay in the worker log) a generic exception the dispatchers
     * report as "Internal error".
     */
    public static function runError(Cache $cache, string $runId): ?\Throwable
    {
        $stored = $cache->get(self::cacheKey($runId) . ':error');
        if (!is_string($stored)) {
            return null;
        }
        $error = json_decode($stored, true);
        $class = is_array($error) ? ($error['class'] ?? null) : null;
        if (is_array($error) && is_string($class) && ($class === A2AError::class || is_subclass_of($class, A2AError::class))) {
            $message = $error['message'] ?? null;
            $data = $error['data'] ?? null;

            return new $class(is_string($message) ? $message : null, is_array($data) ? $data : null);
        }

        return new \RuntimeException(sprintf('The agent failed on the queue worker (run %s); see the worker log.', $runId));
    }

    public function handle(A2AManager $manager): void
    {
        $cache = $manager->runCache();
        $agent = AgentDefinition::fromArray($this->agent);
        $logger = $manager->logger();
        $queueManager = $manager->queueManager();
        $ttl = max(60, $this->timeout + 60);

        if (self::runState($cache, $this->runId) === self::FINISHED) {
            return; // Redelivered after it already ran (retry_after shorter than the run).
        }
        $cache->put(self::cacheKey($this->runId), self::WAITING, $ttl);

        $deadline = microtime(true) + max(1, $this->timeout);
        while (!$queueManager->acquireRunLease($this->taskId, $manager->leaseSeconds())) {
            if (microtime(true) >= $deadline) {
                $logger->error('Task {task} stayed busy with another run; giving up on run {run}.', ['task' => $this->taskId, 'run' => $this->runId]);
                $cache->put(self::cacheKey($this->runId), self::FINISHED, $ttl);

                return;
            }
            usleep(100_000);
        }

        try {
            $cache->put(self::cacheKey($this->runId), self::STARTED, $ttl);

            $request = new SendMessageRequest();
            $request->mergeFromJsonString($this->request);
            $callContext = new ServerCallContext(
                state: ['headers' => $this->caller['headers']],
                user: new SerializedUser($this->caller['user'], $this->caller['authenticated']),
                tenant: $this->caller['tenant'],
                requestedExtensions: $this->caller['extensions'],
            );

            $taskStore = $manager->taskStore();
            $executor = $manager->executor($agent);
            $context = (new SimpleRequestContextBuilder(taskStore: $taskStore))
                ->build($callContext, $request, $this->taskId, $this->contextId);
            $activeTask = (new ActiveTaskRegistry($executor, $taskStore, $queueManager, $logger))
                ->create($this->taskId, $callContext, $this->contextId, $request->getMessage());

            try {
                $activeTask->start($callContext, createTaskIfMissing: true);
            } catch (A2AError $e) {
                // Cancelled (or finished) while the job was waiting in the queue.
                $logger->info('Skipping run {run} of task {task}: {reason}', ['run' => $this->runId, 'task' => $this->taskId, 'reason' => $e->getMessage()]);

                return;
            }

            foreach ($activeTask->run($context) as $_) {
                // ActiveTask saves and publishes each event; the web request streams them.
            }
        } catch (\Throwable $e) {
            // ActiveTask already logged it and marked the task FAILED.
            $cache->put(self::cacheKey($this->runId) . ':error', json_encode([
                'class' => $e instanceof A2AError ? $e::class : null,
                'message' => $e instanceof A2AError ? $e->getMessage() : null,
                'data' => $e instanceof A2AError ? $e->data : null,
            ], JSON_THROW_ON_ERROR | JSON_PARTIAL_OUTPUT_ON_ERROR), $ttl);
            $logger->error('A2A run {run} of task {task} failed: {error}', ['run' => $this->runId, 'task' => $this->taskId, 'error' => $e->getMessage(), 'exception' => $e]);
        } finally {
            $cache->put(self::cacheKey($this->runId), self::FINISHED, $ttl);
            $queueManager->releaseRunLease($this->taskId);
        }
    }

    public function displayName(): string
    {
        return sprintf('A2A task %s', $this->taskId);
    }

    private static function cacheKey(string $runId): string
    {
        return 'a2a:run:' . $runId;
    }
}
