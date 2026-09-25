<?php

declare(strict_types=1);

namespace A2A\Laravel\Stores;

use Illuminate\Database\Eloquent\Model;

/**
 * One stored push-notification config. The whole config (webhook URL,
 * token and authentication credentials) is kept in `config`, encrypted with
 * the app key.
 *
 * @property string $owner
 * @property string $task_id
 * @property string $config_id
 * @property string $config
 *
 * @internal
 */
final class PushNotificationConfigModel extends Model
{
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['config' => 'encrypted'];
    }

    public function getTable(): string
    {
        $prefix = config('a2a.storage.table_prefix', 'a2a_');

        return (is_string($prefix) ? $prefix : 'a2a_') . 'push_notification_configs';
    }

    public function getConnectionName(): ?string
    {
        $connection = config('a2a.storage.connection');

        return is_string($connection) ? $connection : null;
    }
}
