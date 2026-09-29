<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueMutexSchema;
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function getenv;
use function ltrim;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Работает на SQLite; на MariaDB/PostgreSQL — с переменной окружения QUEUE_TEST_DSN
 * (например, `postgres://phpsoftbox:phpsoftbox@postgres:5432/phpsoftbox`).
 */
#[CoversClass(DatabaseDriver::class)]
#[CoversMethod(DatabaseDriver::class, 'push')]
final class DatabaseDriverMutexTransactionTest extends TestCase
{
    /**
     * Проверим, что конфликт mutex при push() внутри транзакции вызывающего кода даёт
     * QueueMutexConflictException и не обрывает эту транзакцию (в PostgreSQL ошибка INSERT делала
     * транзакцию непригодной, и вместо конфликта летела QueryException).
     *
     * @see DatabaseDriver::push()
     */
    #[Test]
    public function mutexConflictKeepsOuterTransactionUsable(): void
    {
        $dsn  = getenv('QUEUE_TEST_DSN');
        $file = null;
        if ($dsn === false || $dsn === '') {
            $file = (string) tempnam(sys_get_temp_dir(), 'queue_mutex_');
            $dsn  = 'sqlite:////' . ltrim($file, '/');
        }

        $manager = new ConnectionManager(new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => ['read' => ['dsn' => $dsn], 'write' => ['dsn' => $dsn]],
            ],
        ]));
        $suffix      = bin2hex(random_bytes(4));
        $schema      = new DatabaseQueueSchema(table: 'queue_jobs_' . $suffix);
        $mutexSchema = new DatabaseQueueMutexSchema(table: 'queue_mutexes_' . $suffix);

        $this->createTables($manager, $schema->table, $mutexSchema->table);

        try {
            $queue = new DatabaseDriver($manager, $schema, 'main', $mutexSchema);

            $queue->push(QueueJob::fromPayload(['n' => 1], 'job-1', mutexKey: 'import'));

            // Второй push с тем же mutex — внутри транзакции, которая после конфликта продолжает работу.
            $conflict = false;
            $size     = $manager->write('main')->transaction(static function () use ($queue, &$conflict): int {
                try {
                    $queue->push(QueueJob::fromPayload(['n' => 2], 'job-2', mutexKey: 'import'));
                } catch (QueueMutexConflictException) {
                    $conflict = true;
                }

                return $queue->size();
            });

            self::assertTrue($conflict);
            self::assertSame(1, $size);
        } finally {
            $manager->write('main')->schema()->dropIfExists($schema->table);
            $manager->write('main')->schema()->dropIfExists($mutexSchema->table);
            if ($file !== null) {
                unlink($file);
            }
        }
    }

    private function createTables(ConnectionManager $manager, string $jobsTable, string $mutexTable): void
    {
        $schema = $manager->write('main')->schema();

        $schema->create($jobsTable, static function (TableBlueprint $table) use ($jobsTable): void {
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

        $schema->create($mutexTable, static function (TableBlueprint $table) use ($mutexTable): void {
            $table->id();
            $table->string('mutex_key', 255)->unique($mutexTable . '_mutex_key_unique');
            $table->string('owner_job_id', 64);
            $table->datetime('expires_datetime');
            $table->datetime('created_datetime')->useCurrent();
            $table->datetime('updated_datetime')->useCurrent();
        });
    }
}
