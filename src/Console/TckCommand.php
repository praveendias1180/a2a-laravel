<?php

declare(strict_types=1);

namespace A2A\Laravel\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * php artisan a2a:tck --tck-dir=/path/to/a2a-tck [--url=] [--level=must]
 *
 * Runs the official A2A TCK (https://github.com/a2aproject/a2a-tck)
 * against this app's agent. The app must already be served, and for the
 * queued runner a queue worker must be running. Serve it with PHP-FPM
 * behind nginx: `php artisan serve` (PHP's built-in server) can queue a
 * request behind an open stream, which makes the TCK's parallel-stream
 * tests fail at random.
 */
final class TckCommand extends Command
{
    protected $signature = 'a2a:tck
        {--tck-dir= : Path to an a2a-tck checkout (its Python dependencies installed)}
        {--url= : Base URL serving /.well-known/agent-card.json (default: APP_URL)}
        {--level=must : must, should, may or all}
        {--transport=jsonrpc,http_json : Transports to test}';

    protected $description = 'Run the official A2A TCK against this app';

    public function handle(): int
    {
        $tckDir = $this->option('tck-dir');
        if (!is_string($tckDir) || !is_file($tckDir . '/run_tck.py')) {
            $this->error('Pass --tck-dir pointing at an a2a-tck checkout (git clone https://github.com/a2aproject/a2a-tck).');

            return self::FAILURE;
        }
        $url = $this->option('url');
        if (!is_string($url) || $url === '') {
            $appUrl = config('app.url');
            $url = is_string($appUrl) ? $appUrl : 'http://localhost';
        }
        $level = $this->option('level');
        $level = is_string($level) ? $level : 'must';
        $transport = $this->option('transport');
        $transport = is_string($transport) ? $transport : 'jsonrpc,http_json';

        $card = @file_get_contents(rtrim($url, '/') . '/.well-known/agent-card.json');
        if ($card === false) {
            $this->error(sprintf('No agent card at %s/.well-known/agent-card.json. Is the app served, with Route::a2a() registered?', rtrim($url, '/')));

            return self::FAILURE;
        }

        $command = ['python3', 'run_tck.py', '--sut-host', rtrim($url, '/'), '--transport', $transport];
        if ($level !== 'all') {
            array_push($command, '--level', $level);
        }
        $process = new Process($command, $tckDir, null, null, null);
        $process->run(function (string $type, string $output): void {
            $this->getOutput()->write($output);
        });

        return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
    }
}
