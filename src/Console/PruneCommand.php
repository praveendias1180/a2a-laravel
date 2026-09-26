<?php

declare(strict_types=1);

namespace A2A\Laravel\Console;

use A2A\Laravel\A2AManager;
use A2A\Laravel\Stores\PushNotificationConfigModel;
use A2A\Server\Events\PdoQueueManager;
use A2A\Server\Tasks\TaskStates;
use Illuminate\Console\Command;

/**
 * php artisan a2a:prune [--days=7]: deletes finished tasks (completed,
 * failed, canceled, rejected) whose last status is older than --days, their
 * push configs, and database events older than --days. Unfinished tasks are
 * never deleted. Redis events expire on their own.
 *
 * Schedule it: Schedule::command('a2a:prune')->daily();
 *
 * @internal Not covered by the 1.x backward-compatibility promise; may change in any release.
 */
final class PruneCommand extends Command
{
    protected $signature = 'a2a:prune {--days=7 : Delete finished tasks and events older than this many days}';

    protected $description = 'Delete old finished A2A tasks and events';

    public function handle(A2AManager $manager): int
    {
        $days = $this->option('days');
        if (!is_numeric($days) || (float) $days < 0) {
            $this->error('--days must be a number of days (0 or more).');

            return self::FAILURE;
        }
        $seconds = (int) round((float) $days * 86400);
        $cutoffMicros = (int) ((microtime(true) - $seconds) * 1_000_000);

        $pdo = $manager->pdo();
        $table = $manager->tablePrefix() . 'tasks';
        $states = implode(', ', array_map('intval', TaskStates::TERMINAL));
        $select = $pdo->prepare("SELECT id FROM {$table} WHERE status_state IN ({$states}) AND status_timestamp IS NOT NULL AND status_timestamp < :cutoff");
        $select->execute(['cutoff' => $cutoffMicros]);
        $ids = array_values(array_filter($select->fetchAll(\PDO::FETCH_COLUMN), 'is_string'));

        foreach (array_chunk($ids, 500) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $pdo->prepare("DELETE FROM {$table} WHERE id IN ({$placeholders})")->execute($chunk);
            PushNotificationConfigModel::query()->whereIn('task_id', $chunk)->delete();
        }

        $events = 0;
        $queueManager = $manager->queueManager();
        if ($queueManager instanceof PdoQueueManager) {
            $events = $queueManager->prune($seconds);
        }

        $this->info(sprintf('Deleted %d finished task(s) and %d event(s) older than %s day(s).', count($ids), $events, $days));

        return self::SUCCESS;
    }
}
