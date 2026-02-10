<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueProgressSchema;
use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use PhpSoftBox\Queue\QueueProgressStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ltrim;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(DatabaseProgressStore::class)]
#[CoversClass(DatabaseQueueProgressSchema::class)]
#[CoversClass(QueueProgressSnapshot::class)]
final class DatabaseProgressStoreTest extends TestCase
{
    /**
     * Проверяет базовые операции сохранения прогресса и флага отмены.
     */
    #[Test]
    public function storesAndUpdatesProgressState(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_progress_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => $dsn],
                    'write' => ['dsn' => $dsn],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        try {
            $manager->connection('main')->schema()->create('queue_progress', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_progress_job_id_unique');
                $table->string('status', 32)->default('queued');
                $table->integer('total')->nullable();
                $table->integer('processed')->default(0);
                $table->integer('percent')->default(0);
                $table->integer('step_percent')->default(1);
                $table->integer('attempt')->default(1);
                $table->text('error')->nullable();
                $table->json('meta')->nullable();
                $table->datetime('cancel_requested_datetime')->nullable();
                $table->datetime('started_datetime')->nullable();
                $table->datetime('finished_datetime')->nullable();
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $store = new DatabaseProgressStore($manager, new DatabaseQueueProgressSchema(), 'main');

            $store->start('job-1', attempt: 2, stepPercent: 5);
            $store->setTotal('job-1', 100);
            $store->setProcessed('job-1', 45, 45);
            $store->setStatus('job-1', QueueProgressStatus::PROCESSING);
            $store->setMeta('job-1', ['source' => 'wb']);
            $store->requestCancellation('job-1');
            $store->setStatus('job-1', QueueProgressStatus::COMPLETED);

            $row = $manager->connection('main')->fetchOne('SELECT * FROM queue_progress WHERE job_id = :job_id LIMIT 1', [
                'job_id' => 'job-1',
            ]);

            self::assertNotNull($row);
            self::assertSame('job-1', (string) ($row['job_id'] ?? null));
            self::assertSame(2, (int) ($row['attempt'] ?? 0));
            self::assertSame(5, (int) ($row['step_percent'] ?? 0));
            self::assertSame(100, (int) ($row['total'] ?? 0));
            self::assertSame(45, (int) ($row['processed'] ?? 0));
            self::assertSame(45, (int) ($row['percent'] ?? 0));
            self::assertSame(QueueProgressStatus::COMPLETED, (string) ($row['status'] ?? ''));
            self::assertNotNull($row['cancel_requested_datetime'] ?? null);
            self::assertNotNull($row['finished_datetime'] ?? null);
            self::assertStringContainsString('"source":"wb"', (string) ($row['meta'] ?? ''));
            self::assertTrue($store->isCancellationRequested('job-1'));

            $snapshot = $store->snapshot('job-1');
            self::assertInstanceOf(QueueProgressSnapshot::class, $snapshot);
            self::assertSame(QueueProgressStatus::COMPLETED, $snapshot->status);
            self::assertSame(2, $snapshot->attempt);
            self::assertSame(100, $snapshot->total);
            self::assertSame(45, $snapshot->processed);
            self::assertSame(45, $snapshot->percent);
            self::assertSame(['source' => 'wb'], $snapshot->meta);
            self::assertNotNull($snapshot->cancelRequestedAt);
            self::assertNotNull($snapshot->startedAt);
            self::assertNotNull($snapshot->updatedAt);
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что completed не затирает terminal-статусы failed/cancelled.
     */
    #[Test]
    public function doesNotOverrideTerminalStatusesWithCompleted(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_progress_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => $dsn],
                    'write' => ['dsn' => $dsn],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        try {
            $manager->connection('main')->schema()->create('queue_progress', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_progress_job_id_unique');
                $table->string('status', 32)->default('queued');
                $table->integer('total')->nullable();
                $table->integer('processed')->default(0);
                $table->integer('percent')->default(0);
                $table->integer('step_percent')->default(1);
                $table->integer('attempt')->default(1);
                $table->text('error')->nullable();
                $table->json('meta')->nullable();
                $table->datetime('cancel_requested_datetime')->nullable();
                $table->datetime('started_datetime')->nullable();
                $table->datetime('finished_datetime')->nullable();
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $store = new DatabaseProgressStore($manager, new DatabaseQueueProgressSchema(), 'main');

            $store->start('job-failed');
            $store->setStatus('job-failed', QueueProgressStatus::FAILED, 'boom');
            $store->setStatus('job-failed', QueueProgressStatus::COMPLETED);

            $failedRow = $manager->connection('main')->fetchOne('SELECT * FROM queue_progress WHERE job_id = :job_id LIMIT 1', [
                'job_id' => 'job-failed',
            ]);

            self::assertNotNull($failedRow);
            self::assertSame(QueueProgressStatus::FAILED, (string) ($failedRow['status'] ?? ''));
            self::assertSame('boom', (string) ($failedRow['error'] ?? ''));

            $store->start('job-cancelled');
            $store->setStatus('job-cancelled', QueueProgressStatus::CANCELLED, 'stop');
            $store->setStatus('job-cancelled', QueueProgressStatus::COMPLETED);

            $cancelledRow = $manager->connection('main')->fetchOne('SELECT * FROM queue_progress WHERE job_id = :job_id LIMIT 1', [
                'job_id' => 'job-cancelled',
            ]);

            self::assertNotNull($cancelledRow);
            self::assertSame(QueueProgressStatus::CANCELLED, (string) ($cancelledRow['status'] ?? ''));
            self::assertSame('stop', (string) ($cancelledRow['error'] ?? ''));

            // Новая попытка сбрасывает состояние, после чего completed снова допустим.
            $store->start('job-failed', attempt: 2, stepPercent: 1);
            $store->setStatus('job-failed', QueueProgressStatus::COMPLETED);

            $retryRow = $manager->connection('main')->fetchOne('SELECT * FROM queue_progress WHERE job_id = :job_id LIMIT 1', [
                'job_id' => 'job-failed',
            ]);

            self::assertNotNull($retryRow);
            self::assertSame(2, (int) ($retryRow['attempt'] ?? 0));
            self::assertSame(QueueProgressStatus::COMPLETED, (string) ($retryRow['status'] ?? ''));
            self::assertNull($retryRow['error'] ?? null);
        } finally {
            @unlink($dbFile);
        }
    }

    #[Test]
    public function preservesCancellationRequestedBeforeStart(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_progress_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => $dsn],
                    'write' => ['dsn' => $dsn],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        try {
            $manager->connection('main')->schema()->create('queue_progress', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_progress_job_id_unique');
                $table->string('status', 32)->default('queued');
                $table->integer('total')->nullable();
                $table->integer('processed')->default(0);
                $table->integer('percent')->default(0);
                $table->integer('step_percent')->default(1);
                $table->integer('attempt')->default(1);
                $table->text('error')->nullable();
                $table->json('meta')->nullable();
                $table->datetime('cancel_requested_datetime')->nullable();
                $table->datetime('started_datetime')->nullable();
                $table->datetime('finished_datetime')->nullable();
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $store = new DatabaseProgressStore($manager, new DatabaseQueueProgressSchema(), 'main');

            self::assertTrue($store->requestCancellation('job-pre-cancel'));
            self::assertTrue($store->isCancellationRequested('job-pre-cancel'));

            $store->start('job-pre-cancel', attempt: 2, stepPercent: 5);

            self::assertTrue($store->isCancellationRequested('job-pre-cancel'));
            $snapshot = $store->snapshot('job-pre-cancel');
            self::assertNotNull($snapshot);
            self::assertSame(2, $snapshot->attempt);
            self::assertNotNull($snapshot->cancelRequestedAt);
        } finally {
            @unlink($dbFile);
        }
    }
}
