<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;
use Illuminate\Support\Facades\DB;

final class PushConfigTest extends TestCase
{
    public function testPushConfigsCanBeCreatedReadListedAndDeletedAndAreEncrypted(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');
        $config = ['taskId' => $taskId, 'id' => 'hook-1', 'url' => 'https://1.1.1.1/a2a-hook', 'token' => 'secret-token-123'];

        $this->rpc('CreateTaskPushNotificationConfig', $config)->assertJsonPath('result.id', 'hook-1');
        $this->rpc('GetTaskPushNotificationConfig', ['taskId' => $taskId, 'id' => 'hook-1'])->assertJsonPath('result.token', 'secret-token-123');
        $this->rpc('ListTaskPushNotificationConfigs', ['taskId' => $taskId])->assertJsonPath('result.configs.0.url', 'https://1.1.1.1/a2a-hook');

        $raw = (string) DB::table('a2a_push_notification_configs')->value('config');
        self::assertStringNotContainsString('secret-token-123', $raw);
        self::assertStringNotContainsString('a2a-hook', $raw);

        $this->rpc('DeleteTaskPushNotificationConfig', ['taskId' => $taskId, 'id' => 'hook-1'])->assertJsonMissingPath('error');
        $this->rpc('ListTaskPushNotificationConfigs', ['taskId' => $taskId])->assertJsonMissingPath('result.configs.0');
    }
}
