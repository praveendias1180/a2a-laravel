<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Support;

use Illuminate\Contracts\Config\Repository;

/**
 * Config shared by a test process and the queue worker processes it starts,
 * so both use the same SQLite file, queue, cache and event log.
 *
 * @phpstan-type Settings array{database: string, cache: string, events: string, redis?: string}
 */
final class SharedEnvironment
{
    /**
     * @param Settings $settings
     */
    public static function apply(Repository $config, array $settings): void
    {
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $config->set('database.default', 'shared');
        $config->set('database.connections.shared', [
            'driver' => 'sqlite',
            'database' => $settings['database'],
            'prefix' => '',
            'foreign_key_constraints' => false,
            'busy_timeout' => 10000,
            'journal_mode' => 'wal',
            'synchronous' => 'normal',
        ]);
        $config->set('queue.default', 'database');
        $config->set('queue.connections.database', [
            'driver' => 'database',
            'connection' => 'shared',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 120,
            'after_commit' => false,
        ]);
        $config->set('cache.default', 'shared-file');
        $config->set('cache.stores.shared-file', ['driver' => 'file', 'path' => $settings['cache']]);
        $config->set('a2a.runner', 'queued');
        $config->set('a2a.queue.timeout', 60);
        $config->set('a2a.sse.poll', 0.05);
        $config->set('a2a.sse.max_idle', 2.0);
        $config->set('a2a.events.driver', $settings['events']);
        if (isset($settings['redis'])) {
            [$host, $port] = array_pad(explode(':', $settings['redis'], 2), 2, '6379');
            $config->set('database.redis.client', 'phpredis');
            $config->set('database.redis.default', ['host' => $host, 'port' => (int) $port, 'database' => 0, 'read_timeout' => 30]);
            $config->set('a2a.events.redis_prefix', 'a2a-test:' . basename($settings['database']) . ':');
        }
    }
}
