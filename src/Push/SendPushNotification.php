<?php

declare(strict_types=1);

namespace A2A\Laravel\Push;

use A2A\Laravel\A2AManager;
use A2A\Types\StreamResponse;
use A2A\Types\Task;
use A2A\Types\TaskArtifactUpdateEvent;
use A2A\Types\TaskStatusUpdateEvent;
use A2A\Utils\ProtoUtils;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Delivers one task update to every webhook registered for the task.
 *
 * Retries happen inside the job (BasePushNotificationSender, with backoff),
 * so the job itself runs once. The notification id stays the same on every
 * retry, so receivers can drop duplicates.
 */
final class SendPushNotification implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly string $taskId,
        public readonly string $update,
        public readonly string $notificationId,
    ) {}

    public static function for(string $taskId, Task|TaskStatusUpdateEvent|TaskArtifactUpdateEvent $event, string $notificationId): self
    {
        return new self($taskId, ProtoUtils::toStreamResponse($event)->serializeToJsonString(), $notificationId);
    }

    public function handle(A2AManager $manager): void
    {
        $response = new StreamResponse();
        $response->mergeFromJsonString($this->update);
        $event = $response->getTask() ?? $response->getStatusUpdate() ?? $response->getArtifactUpdate();
        if ($event === null) {
            return;
        }

        $sender = $manager->directPushSender();
        foreach ($manager->pushConfigStore()->getInfoForDispatch($this->taskId) as $config) {
            $sender->deliver($config, $event, $this->notificationId, $this->taskId);
        }
    }

    public function displayName(): string
    {
        return sprintf('A2A push notification for task %s', $this->taskId);
    }
}
