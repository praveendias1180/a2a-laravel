<?php

declare(strict_types=1);

namespace A2A\Laravel\Push;

use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Server\Tasks\PushNotificationSender;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\Uuid;
use Illuminate\Contracts\Bus\Dispatcher;

/**
 * Hands each push notification to a queue job (SendPushNotification), so a
 * slow or failing webhook never holds up the executor or the stream.
 *
 * Only tasks that have a push config get a job. The job carries the task id,
 * the update and a notification id; it reads the webhook configs (stored
 * encrypted) when it runs, so credentials never sit in the queue payload.
 *
 * Trade-off: with several queue workers, two notifications for the same task
 * can be delivered out of order. Each payload is the full update, so a
 * receiver that needs order compares the task's status timestamp.
 */
final class QueuedPushNotificationSender implements PushNotificationSender
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly PushNotificationConfigStore $configStore,
        private readonly ?string $connection = null,
        private readonly ?string $queue = null,
    ) {}

    public function sendNotification(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event): void
    {
        if ($this->configStore->getInfoForDispatch($taskId) === []) {
            return;
        }

        $job = SendPushNotification::for($taskId, $event, Uuid::v4());
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }
        $this->bus->dispatch($job);
    }
}
