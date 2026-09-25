<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\TestCase;
use Illuminate\Foundation\Auth\User;

final class OwnerScopingTest extends TestCase
{
    public function testAnotherUsersTaskLooksLikeItDoesNotExist(): void
    {
        $alice = new User();
        $alice->forceFill(['id' => 1, 'name' => 'alice', 'email' => 'a@example.com']);
        $bob = new User();
        $bob->forceFill(['id' => 2, 'name' => 'bob', 'email' => 'b@example.com']);

        $taskId = (string) $this->actingAs($alice)->rpc('SendMessage', ['message' => self::message('mine')])->json('result.task.id');

        $this->actingAs($alice)->rpc('GetTask', ['id' => $taskId])->assertJsonPath('result.id', $taskId);
        $this->actingAs($bob)->rpc('GetTask', ['id' => $taskId])->assertJsonPath('error.code', -32001);
        $this->actingAs($bob)->rpc('ListTasks', [])->assertJsonMissingPath('result.tasks.0');
        $this->actingAs($bob)->rpc('CancelTask', ['id' => $taskId])->assertJsonPath('error.code', -32001);
    }
}
