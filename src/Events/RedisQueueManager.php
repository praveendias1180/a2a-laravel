<?php

declare(strict_types=1);

namespace A2A\Laravel\Events;

use A2A\Server\Events\PublishedEvent;
use A2A\Server\Events\QueueManager;

/**
 * A QueueManager on Redis Streams: one stream per task, read with
 * XREAD BLOCK, so a waiting reader wakes as soon as an event is added
 * instead of polling a table.
 *
 * Keys (under $prefix):
 * - `events:{task}`: the stream. Entry ids are `0-{seq}`, so the SDK's
 *   integer sequence numbers map one to one onto stream ids.
 * - `seq:{task}`: the last sequence number. A Lua script bumps it and adds
 *   the entry in one step, so two processes can't publish out of order.
 * - `cancel:{task}` and `lease:{task}`: the cancel flag and run lease.
 *
 * Every key expires: $activeTtl seconds after the last event, or
 * $finishedTtl seconds after an event that ends the task.
 *
 * Commands are sent raw (rawCommand / executeRaw), so phpredis and predis
 * behave the same and the connection's key prefix and serializer don't
 * apply; use $prefix to namespace keys.
 */
final class RedisQueueManager implements QueueManager
{
    private const PUBLISH_SCRIPT = <<<'LUA'
        local seq = redis.call('INCR', KEYS[1])
        redis.call('XADD', KEYS[2], '0-' .. seq, 'p', ARGV[1])
        redis.call('EXPIRE', KEYS[1], ARGV[2])
        redis.call('EXPIRE', KEYS[2], ARGV[2])
        return seq
        LUA;

    /**
     * @param \Closure(string ...): mixed $command sends one raw Redis command and returns the reply
     */
    public function __construct(
        private readonly \Closure $command,
        private readonly string $prefix = 'a2a:',
        private readonly int $activeTtl = 86400,
        private readonly int $finishedTtl = 3600,
    ) {}

    /**
     * Wraps a phpredis \Redis / \RedisCluster or a Predis client.
     */
    public static function forClient(object $client, string $prefix = 'a2a:', int $activeTtl = 86400, int $finishedTtl = 3600): self
    {
        if (method_exists($client, 'rawCommand')) {
            $command = static fn(string ...$args): mixed => $client->rawCommand(...$args);
        } elseif (method_exists($client, 'executeRaw')) {
            $command = static fn(string ...$args): mixed => $client->executeRaw($args);
        } else {
            throw new \InvalidArgumentException(sprintf('Unsupported Redis client %s: use phpredis or predis.', $client::class));
        }

        return new self($command, $prefix, $activeTtl, $finishedTtl);
    }

    public function publish(string $taskId, PublishedEvent $event): int
    {
        $ttl = $event->isTerminal() ? $this->finishedTtl : $this->activeTtl;
        $sequence = $this->call('EVAL', self::PUBLISH_SCRIPT, '2', $this->key('seq', $taskId), $this->key('events', $taskId), $event->toJson(), (string) $ttl);
        if (!is_numeric($sequence)) {
            throw new \RuntimeException('Redis did not return a sequence number for the published event.');
        }
        foreach (['cancel', 'lease'] as $kind) {
            $this->call('EXPIRE', $this->key($kind, $taskId), (string) $ttl);
        }

        return (int) $sequence;
    }

    public function read(string $taskId, int $afterSequence, float $waitSeconds = 0.0): array
    {
        $args = ['XREAD'];
        $blockMs = (int) round($waitSeconds * 1000);
        if ($blockMs > 0) {
            array_push($args, 'BLOCK', (string) $blockMs);
        }
        array_push($args, 'STREAMS', $this->key('events', $taskId), '0-' . $afterSequence);

        $reply = $this->call(...$args);
        $events = [];
        if (!is_array($reply)) {
            return $events;
        }
        foreach ($reply as $stream) {
            $entries = is_array($stream) ? ($stream[1] ?? null) : null;
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $entry) {
                if (!is_array($entry) || !is_string($entry[0] ?? null) || !is_array($entry[1] ?? null)) {
                    continue;
                }
                $sequence = (int) substr($entry[0], (int) strpos($entry[0], '-') + 1);
                $fields = $entry[1];
                $payload = null;
                for ($i = 0; $i + 1 < count($fields); $i += 2) {
                    if ($fields[$i] === 'p' && is_string($fields[$i + 1])) {
                        $payload = $fields[$i + 1];
                    }
                }
                if ($payload !== null) {
                    $events[$sequence] = PublishedEvent::fromJson($payload);
                }
            }
        }
        ksort($events);

        return $events;
    }

    public function lastSequence(string $taskId): int
    {
        $value = $this->call('GET', $this->key('seq', $taskId));

        return is_numeric($value) ? (int) $value : 0;
    }

    public function requestCancel(string $taskId): void
    {
        $this->call('SET', $this->key('cancel', $taskId), '1', 'EX', (string) $this->activeTtl);
    }

    public function isCancelRequested(string $taskId): bool
    {
        return $this->call('GET', $this->key('cancel', $taskId)) === '1';
    }

    public function acquireRunLease(string $taskId, int $ttlSeconds): bool
    {
        $reply = $this->call('SET', $this->key('lease', $taskId), (string) getmypid(), 'NX', 'PX', (string) max(1, $ttlSeconds * 1000));

        return $reply === true || $reply === 'OK' || (is_object($reply) && method_exists($reply, '__toString') && (string) $reply === 'OK');
    }

    public function releaseRunLease(string $taskId): void
    {
        $this->call('DEL', $this->key('lease', $taskId));
    }

    public function hasActiveRunLease(string $taskId): bool
    {
        $exists = $this->call('EXISTS', $this->key('lease', $taskId));

        return is_numeric($exists) && (int) $exists > 0;
    }

    private function key(string $kind, string $taskId): string
    {
        return $this->prefix . $kind . ':' . $taskId;
    }

    private function call(string ...$args): mixed
    {
        return ($this->command)(...$args);
    }
}
