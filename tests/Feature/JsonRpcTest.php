<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;

final class JsonRpcTest extends TestCase
{
    public function testSendMessageRunsTheExecutorAndReturnsTheTask(): void
    {
        $response = $this->rpc('SendMessage', ['message' => self::message('hello')])->assertOk();

        $response->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
        $response->assertJsonPath('result.task.artifacts.0.parts.0.text', 'echo: hello');
    }

    public function testAMessageReplyComesBackAsAMessage(): void
    {
        $this->rpc('SendMessage', ['message' => self::message('message: hi')])
            ->assertOk()
            ->assertJsonPath('result.message.parts.0.text', 'reply: hi');
    }

    public function testGetListAndCancel(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_INPUT_REQUIRED')
            ->json('result.task.id');

        $this->rpc('GetTask', ['id' => $taskId])->assertJsonPath('result.id', $taskId);
        $this->rpc('ListTasks', [])->assertJsonPath('result.tasks.0.id', $taskId);
        $this->rpc('CancelTask', ['id' => $taskId])->assertJsonPath('result.status.state', 'TASK_STATE_CANCELED');
        $this->rpc('CancelTask', ['id' => $taskId])->assertJsonPath('error.code', -32002);
    }

    public function testAFollowUpContinuesAPausedTask(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');

        $this->rpc('SendMessage', ['message' => self::message('more', $taskId)])
            ->assertJsonPath('result.task.id', $taskId)
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
    }

    public function testV03MethodsAreOffByDefault(): void
    {
        $this->rpc('message/send', ['message' => ['kind' => 'message', 'messageId' => 'm', 'role' => 'user', 'parts' => []]])
            ->assertJsonPath('error.code', -32601);
    }

    public function testUnknownTasksAreNotFound(): void
    {
        $this->rpc('GetTask', ['id' => 'nope'])->assertJsonPath('error.code', -32001);
    }

    public function testAFailingExecutorFailsTheTaskWithoutLeakingTheError(): void
    {
        $response = $this->rpc('SendMessage', ['message' => self::message('fail')]);

        self::assertStringNotContainsString('boom', (string) $response->getContent());
    }

    public function testSendStreamingMessageStreamsEveryEventInOrder(): void
    {
        $response = $this->rpc('SendStreamingMessage', ['message' => self::message('hello')]);
        $response->assertOk();
        self::assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));
        self::assertSame('no', $response->headers->get('X-Accel-Buffering'));

        $kinds = array_map(static fn(array $e): string => (string) array_key_first(is_array($e['result'] ?? null) ? $e['result'] : []), self::sseEvents($response->streamedContent()));
        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], $kinds);
    }

    public function testSubscribeToTaskStreamsTheCurrentTaskFirst(): void
    {
        $taskId = (string) $this->rpc('SendMessage', ['message' => self::message('input')])->json('result.task.id');

        $events = self::sseEvents($this->rpc('SubscribeToTask', ['id' => $taskId])->streamedContent());
        self::assertSame($taskId, $events[0]['result']['task']['id'] ?? null);
    }
}
