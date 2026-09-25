<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Unit;

use A2A\Laravel\Events\RedisQueueManager;
use A2A\Server\Events\QueueManager;
use A2A\Tests\Server\Events\QueueManagerContract;
use A2A\Types\TaskState;

/**
 * Runs the core QueueManager contract against Redis Streams. Needs a Redis
 * server: set A2A_TEST_REDIS=host:port (skipped otherwise).
 */
final class RedisQueueManagerTest extends QueueManagerContract
{
    private static ?\Redis $redis = null;

    private string $prefix = '';

    protected function setUp(): void
    {
        $target = getenv('A2A_TEST_REDIS');
        if (!is_string($target) || $target === '' || !extension_loaded('redis')) {
            self::markTestSkipped('Set A2A_TEST_REDIS=host:port (and install phpredis) to run the Redis tests.');
        }
        if (self::$redis === null) {
            [$host, $port] = array_pad(explode(':', $target, 2), 2, '6379');
            self::$redis = new \Redis();
            self::$redis->connect($host, (int) $port);
        }
        $this->prefix = 'a2a-test:' . bin2hex(random_bytes(4)) . ':';
    }

    protected function createManager(): QueueManager
    {
        self::assertNotNull(self::$redis);

        return RedisQueueManager::forClient(self::$redis, $this->prefix);
    }

    public function testTwoConnectionsShareTheLogAndFlags(): void
    {
        $target = (string) getenv('A2A_TEST_REDIS');
        [$host, $port] = array_pad(explode(':', $target, 2), 2, '6379');
        $other = new \Redis();
        $other->connect($host, (int) $port);

        $publisher = $this->createManager();
        $subscriber = RedisQueueManager::forClient($other, $this->prefix);

        $sequence = $publisher->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        $publisher->requestCancel('task-1');
        $publisher->acquireRunLease('task-1', 60);

        self::assertSame([$sequence], array_keys($subscriber->read('task-1', 0)));
        self::assertTrue($subscriber->isCancelRequested('task-1'));
        self::assertTrue($subscriber->hasActiveRunLease('task-1'));
        self::assertFalse($subscriber->acquireRunLease('task-1', 60));
    }

    public function testBlockingReadWakesUpWhenAnEventArrives(): void
    {
        $manager = $this->createManager();
        $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        $start = microtime(true);
        $events = $manager->read('task-1', 0, 5.0);

        self::assertCount(1, $events);
        self::assertLessThan(1.0, microtime(true) - $start);
    }

    public function testKeysExpireSoonerOnceTheTaskFinishes(): void
    {
        self::assertNotNull(self::$redis);
        $manager = RedisQueueManager::forClient(self::$redis, $this->prefix, activeTtl: 1000, finishedTtl: 50);
        $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_WORKING));
        self::assertGreaterThan(500, self::$redis->ttl($this->prefix . 'events:task-1'));

        $manager->publish('task-1', self::statusEvent('task-1', TaskState::TASK_STATE_COMPLETED));
        self::assertLessThanOrEqual(50, self::$redis->ttl($this->prefix . 'events:task-1'));
        self::assertLessThanOrEqual(50, self::$redis->ttl($this->prefix . 'seq:task-1'));
    }
}
