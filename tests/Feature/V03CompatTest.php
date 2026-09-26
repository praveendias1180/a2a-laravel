<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;

/**
 * config a2a.v0_3_compat: the same Route::a2a() routes also serve A2A v0.3
 * clients, and the auto-filled card advertises the v0.3 interfaces.
 */
final class V03CompatTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('a2a.v0_3_compat', true);
    }

    public function testTheCardAdvertisesV03(): void
    {
        $card = $this->get('/.well-known/agent-card.json')->assertOk();

        $card->assertJsonPath('preferredTransport', 'JSONRPC');
        $card->assertJsonPath('protocolVersion', '0.3');
        self::assertStringEndsWith('/a2a/jsonrpc', (string) $card->json('url'));
        $versions = array_column((array) $card->json('supportedInterfaces'), 'protocolVersion');
        self::assertSame(['1.0', '1.0', '0.3', '0.3'], $versions);
    }

    public function testV03JsonRpc(): void
    {
        $response = $this->postJson('/a2a/jsonrpc', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'message/send',
            'params' => ['message' => ['kind' => 'message', 'messageId' => 'm1', 'role' => 'user', 'parts' => [['kind' => 'text', 'text' => 'hello']]]],
        ])->assertOk();

        $response->assertJsonPath('result.kind', 'task');
        $response->assertJsonPath('result.status.state', 'completed');
        $response->assertJsonPath('result.artifacts.0.parts.0.text', 'echo: hello');

        $this->postJson('/a2a/jsonrpc', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tasks/get', 'params' => ['id' => 'nope']])
            ->assertJsonPath('error.code', -32001);
    }

    public function testV03Rest(): void
    {
        $response = $this->postJson('/a2a/rest/v1/message:send', [
            'message' => ['messageId' => 'm1', 'role' => 'ROLE_USER', 'content' => [['text' => 'hello']]],
            'configuration' => ['blocking' => true],
        ])->assertOk();

        $response->assertJsonPath('task.status.state', 'TASK_STATE_COMPLETED');
        $taskId = (string) $response->json('task.id');

        $this->get("/a2a/rest/v1/tasks/{$taskId}")->assertOk()->assertJsonPath('id', $taskId);
    }

    public function testV10KeepsWorking(): void
    {
        $this->rpc('SendMessage', ['message' => self::message('hello')])
            ->assertJsonPath('result.task.status.state', 'TASK_STATE_COMPLETED');
    }
}
