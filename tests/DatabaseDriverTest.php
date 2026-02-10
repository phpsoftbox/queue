<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\QueueJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ltrim;
use function sys_get_temp_dir;
use function time;
use function uniqid;
use function unlink;

#[CoversClass(DatabaseDriver::class)]
#[CoversClass(DatabaseQueueSchema::class)]
final class DatabaseDriverTest extends TestCase
{
    /**
     * Проверяет запись и чтение заданий из БД.
     */
    #[Test]
    public function testPushAndPop(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');
        $config = [
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read' => [
                        'dsn' => $dsn,
                    ],
                    'write' => [
                        'dsn' => $dsn,
                    ],
                ],
            ],
        ];

        $factory = new DatabaseFactory($config);

        $manager = new ConnectionManager($factory);

        try {
            $manager->connection()->schema()->create('queue_jobs', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_jobs_job_id_unique');
                $table->json('payload');
                $table->integer('attempts')->default(0);
                $table->integer('priority')->default(0);
                $table->datetime('available_datetime');
                $table->datetime('reserved_datetime')->nullable();
                $table->datetime('created_datetime')->useCurrent();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main');

            $queue->push(QueueJob::fromPayload(['task' => 'ping'], 'job-1', priority: 5, availableAt: time()));

            $this->assertSame(1, $queue->size());

            $job = $queue->pop();

            $this->assertNotNull($job);
            $this->assertSame('job-1', $job->id());
            $this->assertSame(['task' => 'ping'], $job->payload());
            $this->assertSame(0, $queue->size());
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что pop() возвращает null при пустой очереди.
     */
    #[Test]
    public function testPopReturnsNullWhenEmpty(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');
        $config = [
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read' => [
                        'dsn' => $dsn,
                    ],
                    'write' => [
                        'dsn' => $dsn,
                    ],
                ],
            ],
        ];

        $factory = new DatabaseFactory($config);

        $manager = new ConnectionManager($factory);

        try {
            $manager->connection()->schema()->create('queue_jobs', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_jobs_job_id_unique');
                $table->json('payload');
                $table->integer('attempts')->default(0);
                $table->integer('priority')->default(0);
                $table->datetime('available_datetime');
                $table->datetime('reserved_datetime')->nullable();
                $table->datetime('created_datetime')->useCurrent();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main');

            $this->assertNull($queue->pop());
        } finally {
            @unlink($dbFile);
        }
    }
}
