<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\A2AManager;
use A2A\Laravel\Push\SendPushNotification;
use A2A\Laravel\Tests\TestCase;
use A2A\Server\Tasks\BasePushNotificationSender;
use A2A\Tests\Client\Support\FakeHttpSender;
use A2A\Types\StreamResponse;
use Illuminate\Support\Facades\Queue;

final class PushNotificationsTest extends TestCase
{
    public function testTaskUpdatesAreQueuedWithoutCredentialsInThePayload(): void
    {
        Queue::fake();

        $this->rpc('SendMessage', [
            'message' => self::message('hello'),
            'configuration' => ['taskPushNotificationConfig' => [
                'url' => 'https://1.1.1.1/hook',
                'token' => 'secret-token',
                'authentication' => ['scheme' => 'Bearer', 'credentials' => 'secret-credentials'],
            ]],
        ])->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');

        $jobs = Queue::pushed(SendPushNotification::class);
        self::assertGreaterThanOrEqual(2, count($jobs));
        foreach ($jobs as $job) {
            $payload = serialize($job);
            self::assertStringNotContainsString('secret-token', $payload);
            self::assertStringNotContainsString('secret-credentials', $payload);
            self::assertStringNotContainsString('1.1.1.1', $payload);
        }
        $lastJob = $jobs->last();
        self::assertInstanceOf(SendPushNotification::class, $lastJob);
        $last = new StreamResponse();
        $last->mergeFromJsonString($lastJob->update);
        self::assertSame('TASK_STATE_COMPLETED', \A2A\Types\TaskState::name($last->getStatusUpdate()?->getStatus()?->getState() ?? 0));
    }

    public function testTheJobDeliversToTheWebhookWithAuthAndToken(): void
    {
        $http = new FakeHttpSender();
        foreach (range(1, 6) as $_) {
            $http->queueBody('');
        }
        $this->app->instance('a2a.push.http_client', $http);

        $this->rpc('SendMessage', [
            'message' => self::message('hello'),
            'configuration' => ['taskPushNotificationConfig' => [
                'url' => 'https://1.1.1.1/hook',
                'token' => 'secret-token',
                'authentication' => ['scheme' => 'Bearer', 'credentials' => 'abc'],
            ]],
        ])->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');

        // queue.default is `sync` in these tests, so the jobs already ran.
        self::assertNotEmpty($http->requests);
        $request = $http->lastRequest();
        self::assertSame('https://1.1.1.1/hook', $request->url);
        self::assertSame('Bearer abc', $request->headers['Authorization'] ?? null);
        self::assertSame('secret-token', $request->headers[BasePushNotificationSender::TOKEN_HEADER] ?? null);
        self::assertSame('TASK_STATE_COMPLETED', $http->lastJson()['statusUpdate']['status']['state'] ?? null);
    }

    public function testAPrivateWebhookIsRejectedWhenTheConfigIsCreated(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');

        $this->rpc('CreateTaskPushNotificationConfig', ['taskId' => $taskId, 'url' => 'http://169.254.169.254/latest'])
            ->assertJsonPath('error.code', -32602);
    }

    public function testAllowedHostsExemptALocalWebhook(): void
    {
        config(['a2a.push.allowed_hosts' => ['127.0.0.1']]);
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');

        $this->rpc('CreateTaskPushNotificationConfig', ['taskId' => $taskId, 'url' => 'http://127.0.0.1:9000/hook'])
            ->assertJsonMissingPath('error');
    }

    public function testDisabledPushSendsNothing(): void
    {
        config(['a2a.push.enabled' => false]);
        Queue::fake();

        $this->rpc('SendMessage', [
            'message' => self::message('hello'),
            'configuration' => ['taskPushNotificationConfig' => ['url' => 'https://1.1.1.1/hook']],
        ]);

        Queue::assertNotPushed(SendPushNotification::class);
    }

    public function testInlineSendingWhenTheQueueIsOff(): void
    {
        config(['a2a.push.queue' => false]);
        $manager = $this->app->make(A2AManager::class);

        self::assertInstanceOf(BasePushNotificationSender::class, $manager->pushSender((new \A2A\Laravel\Tests\Fixtures\TestAgentCard())->agentCard()));
    }
}
