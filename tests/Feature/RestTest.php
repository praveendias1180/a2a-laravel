<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;

final class RestTest extends TestCase
{
    public function testSendGetAndListOverRest(): void
    {
        $taskId = (string) $this->postJson('/a2a/rest/message:send', ['message' => self::message('rest')], ['A2A-Version' => '1.0'])
            ->assertOk()
            ->assertJsonPath('task.status.state', 'TASK_STATE_COMPLETED')
            ->assertJsonPath('task.artifacts.0.parts.0.text', 'echo: rest')
            ->json('task.id');

        $this->getJson("/a2a/rest/tasks/{$taskId}", ['A2A-Version' => '1.0'])->assertOk()->assertJsonPath('id', $taskId);
        $this->getJson('/a2a/rest/tasks', ['A2A-Version' => '1.0'])->assertOk()->assertJsonPath('tasks.0.id', $taskId);
    }

    public function testStreamOverRest(): void
    {
        $response = $this->postJson('/a2a/rest/message:stream', ['message' => self::message('rest stream')], ['A2A-Version' => '1.0']);
        $events = self::sseEvents($response->streamedContent());

        self::assertSame(['task', 'statusUpdate', 'artifactUpdate', 'statusUpdate'], array_map(static fn(array $e): string => (string) array_key_first($e), $events));
    }

    public function testCancelAFinishedTaskIsA409(): void
    {
        $taskId = (string) $this->postJson('/a2a/rest/message:send', ['message' => self::message('done')], ['A2A-Version' => '1.0'])->json('task.id');

        $this->postJson("/a2a/rest/tasks/{$taskId}:cancel", [], ['A2A-Version' => '1.0'])
            ->assertStatus(409)
            ->assertJsonPath('error.details.0.reason', 'TASK_NOT_CANCELABLE');
    }

    public function testUnknownTaskIs404AndUnknownPathToo(): void
    {
        $this->getJson('/a2a/rest/tasks/missing', ['A2A-Version' => '1.0'])->assertStatus(404);
        $this->getJson('/a2a/rest/nothing-here', ['A2A-Version' => '1.0'])->assertStatus(404);
    }
}
