<?php

declare(strict_types=1);

namespace A2A\Laravel\Stores;

use A2A\Server\OwnerResolver;
use A2A\Server\ServerCallContext;
use A2A\Server\Tasks\PushNotificationConfigStore;
use A2A\Types\TaskPushNotificationConfig;

/**
 * Push-notification configs in the app's database, scoped by owner like
 * tasks. Configs are stored encrypted (Laravel's `encrypted` cast), because
 * they hold the webhook's token and credentials.
 *
 * Same behaviour as the core InMemoryPushNotificationConfigStore (an empty
 * config id defaults to the task id, a repeat id replaces the old config).
 */
final class DatabasePushNotificationConfigStore implements PushNotificationConfigStore
{
    /** @var \Closure(ServerCallContext): string */
    private readonly \Closure $ownerResolver;

    /**
     * @param (\Closure(ServerCallContext): string)|null $ownerResolver
     */
    public function __construct(?\Closure $ownerResolver = null)
    {
        $this->ownerResolver = $ownerResolver ?? OwnerResolver::default();
    }

    public function setInfo(string $taskId, TaskPushNotificationConfig $config, ServerCallContext $context): void
    {
        $copy = new TaskPushNotificationConfig();
        $copy->mergeFrom($config);
        if ($copy->getId() === '') {
            $copy->setId($taskId);
        }
        $copy->setTaskId($taskId);

        PushNotificationConfigModel::query()->updateOrCreate(
            ['owner' => ($this->ownerResolver)($context), 'task_id' => $taskId, 'config_id' => $copy->getId()],
            ['config' => $copy->serializeToJsonString()],
        );
    }

    public function getInfo(string $taskId, ServerCallContext $context): array
    {
        return self::decodeAll(PushNotificationConfigModel::query()
            ->where('owner', ($this->ownerResolver)($context))
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->pluck('config'));
    }

    public function getInfoForDispatch(string $taskId): array
    {
        return self::decodeAll(PushNotificationConfigModel::query()
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->pluck('config'));
    }

    /**
     * @param iterable<mixed> $stored
     *
     * @return list<TaskPushNotificationConfig>
     */
    private static function decodeAll(iterable $stored): array
    {
        $configs = [];
        foreach ($stored as $json) {
            if (!is_string($json)) {
                continue;
            }
            $config = new TaskPushNotificationConfig();
            $config->mergeFromJsonString($json);
            $configs[] = $config;
        }

        return $configs;
    }

    public function deleteInfo(string $taskId, ServerCallContext $context, ?string $configId = null): void
    {
        $query = PushNotificationConfigModel::query()
            ->where('owner', ($this->ownerResolver)($context))
            ->where('task_id', $taskId);
        if ($configId !== null) {
            $query->where('config_id', $configId);
        }
        $query->delete();
    }
}
