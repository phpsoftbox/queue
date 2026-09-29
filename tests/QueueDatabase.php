<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueMutexSchema;
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use RuntimeException;

use function bin2hex;
use function date;
use function getenv;
use function ltrim;
use function random_bytes;
use function sprintf;
use function sys_get_temp_dir;
use function tempnam;
use function time;
use function unlink;

/**
 * Таблицы очереди и mutex со случайными именами: на временной SQLite или на БД из `QUEUE_TEST_DSN`
 * (MariaDB, MySQL, PostgreSQL).
 */
final class QueueDatabase
{
    public readonly ConnectionManager $connections;
    public readonly DatabaseQueueSchema $schema;
    public readonly DatabaseQueueMutexSchema $mutexSchema;
    private ?string $file = null;

    public function __construct()
    {
        $dsn = getenv('QUEUE_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $file = tempnam(sys_get_temp_dir(), 'queue_jobs_');
            if ($file === false) {
                throw new RuntimeException('Cannot create temporary test database.');
            }
            $this->file = $file;
            $dsn        = 'sqlite:////' . ltrim($file, '/');
        }

        $this->connections = new ConnectionManager(new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => ['read' => ['dsn' => $dsn], 'write' => ['dsn' => $dsn]],
            ],
        ]));

        $suffix       = bin2hex(random_bytes(4));
        $this->schema = new DatabaseQueueSchema(table: 'queue_jobs_' . $suffix);

        $this->mutexSchema = new DatabaseQueueMutexSchema(table: 'queue_mutexes_' . $suffix);

        $jobsTable  = $this->schema->table;
        $mutexTable = $this->mutexSchema->table;
        $builder    = $this->connections->write('main')->schema();

        $builder->create($jobsTable, static function (TableBlueprint $table) use ($jobsTable): void {
            $table->id();
            $table->string('job_id', 64)->unique($jobsTable . '_job_id_unique');
            $table->json('payload');
            $table->integer('attempts')->default(0);
            $table->integer('priority')->default(0);
            $table->datetime('available_datetime');
            $table->datetime('reserved_datetime')->nullable();
            $table->datetime('created_datetime')->useCurrent();
            $table->string('mutex_key', 255)->nullable();
            $table->integer('mutex_ttl_seconds')->nullable();
            $table->integer('is_cancellable')->default(0);
        });

        $builder->create($mutexTable, static function (TableBlueprint $table) use ($mutexTable): void {
            $table->id();
            $table->string('mutex_key', 255)->unique($mutexTable . '_mutex_key_unique');
            $table->string('owner_job_id', 64);
            $table->datetime('expires_datetime');
            $table->datetime('created_datetime')->useCurrent();
            $table->datetime('updated_datetime')->useCurrent();
        });
    }

    public function driver(): DatabaseDriver
    {
        return new DatabaseDriver($this->connections, $this->schema, 'main', $this->mutexSchema);
    }

    /**
     * Истекает visibility timeout всех зарезервированных задач — как после падения воркера.
     */
    public function expireReservations(): void
    {
        $connection = $this->connections->write('main');
        $connection->execute(
            sprintf(
                'UPDATE %s SET reserved_datetime = :past WHERE reserved_datetime IS NOT NULL',
                $connection->table($this->schema->table),
            ),
            ['past' => date('Y-m-d H:i:s', time() - 60)],
        );
    }

    public function storedAttempts(string $jobId): ?int
    {
        $connection = $this->connections->read('main');
        $row        = $connection->fetchOne(
            sprintf('SELECT attempts FROM %s WHERE job_id = :job_id', $connection->table($this->schema->table)),
            ['job_id' => $jobId],
        );

        return $row === null ? null : (int) $row['attempts'];
    }

    public function close(): void
    {
        $builder = $this->connections->write('main')->schema();
        $builder->dropIfExists($this->schema->table);
        $builder->dropIfExists($this->mutexSchema->table);
        if ($this->file !== null) {
            unlink($this->file);
        }
    }
}
