<?php

declare(strict_types=1);

namespace A2A\Laravel\Tests\Feature;

use A2A\Laravel\Tests\Support\SharedEnvironment;
use A2A\Laravel\Tests\TestCase;
use Symfony\Component\Process\Process;

/**
 * Runs the queued runner for real: the test process is the web request and
 * a separate `queue:work` process (tests/Support/worker.php) runs the
 * executor, sharing a SQLite file, the database queue and a file cache.
 */
abstract class CrossProcessTestCase extends TestCase
{
    private string $database = '';

    private string $cacheDir = '';

    /** @var list<Process> */
    private array $workers = [];

    abstract protected function eventsDriver(): string;

    protected function setUp(): void
    {
        $this->database = (string) tempnam(sys_get_temp_dir(), 'a2a-laravel-db-');
        $this->cacheDir = sys_get_temp_dir() . '/a2a-laravel-cache-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir);
        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop(1);
        }
        parent::tearDown();
        foreach ([$this->database, $this->database . '-wal', $this->database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->removeDir($this->cacheDir);
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        SharedEnvironment::apply($app['config'], $this->settings());
    }

    /**
     * @return array{database: string, cache: string, events: string, redis?: string}
     */
    protected function settings(): array
    {
        $settings = ['database' => $this->database, 'cache' => $this->cacheDir, 'events' => $this->eventsDriver()];
        $redis = getenv('A2A_TEST_REDIS');
        if (is_string($redis) && $redis !== '') {
            $settings['redis'] = $redis;
        }

        return $settings;
    }

    protected function startWorker(): void
    {
        $worker = new Process(
            [PHP_BINARY, __DIR__ . '/../Support/worker.php'],
            null,
            ['A2A_SHARED_ENV' => json_encode($this->settings(), JSON_THROW_ON_ERROR)],
        );
        $worker->start();
        $this->workers[] = $worker;
    }

    /**
     * Output of the workers so far (for failure messages).
     */
    protected function workerOutput(): string
    {
        $out = '';
        foreach ($this->workers as $worker) {
            $out .= $worker->getOutput() . $worker->getErrorOutput();
        }

        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
