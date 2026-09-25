<?php

declare(strict_types=1);

use A2A\Server\Events\PdoQueueManager;
use A2A\Server\Tasks\PdoTaskStore;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tables the A2A bridge uses. The task and event tables are created by
 * the core SDK's own stores, so their schema always matches the code that
 * reads them. The push-config table is a regular Laravel table.
 */
return new class extends Migration {
    public function up(): void
    {
        $pdo = DB::connection($this->connectionName())->getPdo();
        (new PdoTaskStore($pdo, $this->prefix(), createTable: false))->createTable();
        (new PdoQueueManager($pdo, $this->prefix(), createTables: false))->createTables();

        Schema::connection($this->connectionName())->create($this->prefix() . 'push_notification_configs', function (Blueprint $table): void {
            $table->id();
            $table->string('owner')->default('');
            $table->string('task_id');
            $table->string('config_id');
            $table->text('config');
            $table->timestamps();
            $table->unique(['owner', 'task_id', 'config_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connectionName())->dropIfExists($this->prefix() . 'push_notification_configs');
        foreach (['task_events', 'task_flags', 'tasks'] as $table) {
            // These tables are created by the SDK without the connection's
            // table prefix, so drop them the same way.
            DB::connection($this->connectionName())->getPdo()->exec('DROP TABLE IF EXISTS ' . $this->prefix() . $table);
        }
    }

    public function getConnection(): ?string
    {
        return $this->connectionName();
    }

    private function connectionName(): ?string
    {
        $connection = config('a2a.storage.connection');

        return is_string($connection) ? $connection : null;
    }

    private function prefix(): string
    {
        $prefix = config('a2a.storage.table_prefix', 'a2a_');

        return is_string($prefix) ? $prefix : 'a2a_';
    }
};
