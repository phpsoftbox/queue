<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
                $table->string('mutex_key', 255)->nullable();
                $table->integer('mutex_ttl_seconds')->nullable();
                $table->integer('is_cancellable')->default(0);
            });

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
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
                $table->string('mutex_key', 255)->nullable();
                $table->integer('mutex_ttl_seconds')->nullable();
                $table->integer('is_cancellable')->default(0);
            });

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main');

            $this->assertNull($queue->pop());
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что pop() повторяет попытку после разрыва соединения,
     * если менеджер подключений поддерживает reconnect.
     */
    #[Test]
    public function testPopRetriesAfterConnectionLostWhenManagerSupportsReconnect(): void
    {
        $primary = $this->createMock(ConnectionInterface::class);
        $primary->method('isReadOnly')->willReturn(false);
        $primary->method('transaction')->willThrowException(new RuntimeException('MySQL server has gone away'));

        $secondary = $this->createMock(ConnectionInterface::class);
        $secondary->method('isReadOnly')->willReturn(false);
        $secondary->method('table')->willReturnCallback(static fn (string $name): string => $name);
        $secondary->method('fetchOne')->willReturn(null);
        $secondary->method('transaction')->willReturnCallback(static function (callable $callback) use ($secondary): mixed {
            return $callback($secondary);
        });

        $manager = new class ($primary, $secondary) implements ConnectionManagerInterface {
            public int $reconnectCalls = 0;
            public int $writeCalls     = 0;

            public function __construct(
                private readonly ConnectionInterface $primary,
                private readonly ConnectionInterface $secondary,
            ) {
            }

            public function connection(string $name = 'default'): ConnectionInterface
            {
                return $this->write($name);
            }

            public function read(string $name = 'default'): ConnectionInterface
            {
                return $this->write($name);
            }

            public function write(string $name = 'default'): ConnectionInterface
            {
                ++$this->writeCalls;

                return $this->writeCalls === 1 ? $this->primary : $this->secondary;
            }

            public function reconnect(string $name = 'default'): ConnectionInterface
            {
                ++$this->reconnectCalls;

                return $this->secondary;
            }
        };

        $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main');

        $job = $queue->pop();

        self::assertNull($job);
        self::assertSame(1, $manager->reconnectCalls);
    }

    /**
     * Проверяет, что второй job с тем же mutex_key отклоняется исключением конфликта.
     */
    #[Test]
    public function testPushFailsWhenMutexAlreadyAcquiredByAnotherJob(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');
        $config = [
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => $dsn],
                    'write' => ['dsn' => $dsn],
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
                $table->string('mutex_key', 255)->nullable();
                $table->integer('mutex_ttl_seconds')->nullable();
                $table->integer('is_cancellable')->default(0);
            });

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), connectionName: 'main');

            $queue->push(QueueJob::fromPayload(['task' => 'one'], 'job-1', availableAt: time())->withMutex('import:10'));

            $this->expectException(QueueMutexConflictException::class);
            $queue->push(QueueJob::fromPayload(['task' => 'two'], 'job-2', availableAt: time())->withMutex('import:10'));
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет lifecycle резервирования: задача остаётся в очереди до подтверждения acknowledge().
     */
    #[Test]
    public function testReserveAndAcknowledgeLifecycle(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
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
            $manager->connection()->schema()->create('queue_jobs', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_jobs_job_id_unique');
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

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main', visibilityTimeoutSeconds: 60);

            $queue->push(QueueJob::fromPayload(['task' => 'reserve'], 'job-reserve', availableAt: time()));

            $reserved = $queue->reserve();
            self::assertNotNull($reserved);
            self::assertSame('job-reserve', $reserved->id());
            self::assertSame(1, $queue->size(), 'Задача должна оставаться в очереди до acknowledge().');

            $secondTry = $queue->reserve();
            self::assertNull($secondTry, 'Зарезервированная задача не должна выдаваться до visibility timeout.');

            $queue->acknowledge($reserved);
            self::assertSame(0, $queue->size());
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что release() возвращает задачу в очередь и обновляет attempts.
     */
    #[Test]
    public function testReleaseUpdatesAttemptsAndMakesJobAvailableAgain(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
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
            $manager->connection()->schema()->create('queue_jobs', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_jobs_job_id_unique');
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

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main', visibilityTimeoutSeconds: 60);

            $queue->push(QueueJob::fromPayload(['task' => 'retry'], 'job-retry', availableAt: time()));

            $reserved = $queue->reserve();
            self::assertNotNull($reserved);

            $queue->release($reserved->withAttempt());

            $retried = $queue->reserve();
            self::assertNotNull($retried);
            self::assertSame(1, $retried->attempts());
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что флаг is_cancellable сохраняется в БД и корректно гидратится обратно в QueueJob.
     */
    #[Test]
    public function testPersistsCancellableFlag(): void
    {
        $dbFile = sys_get_temp_dir() . '/psb_queue_' . uniqid('', true) . '.sqlite';
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
            $manager->connection()->schema()->create('queue_jobs', function (TableBlueprint $table): void {
                $table->id();
                $table->string('job_id', 64)->unique('queue_jobs_job_id_unique');
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

            $manager->connection()->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
                $table->id();
                $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique');
                $table->string('owner_job_id', 64);
                $table->datetime('expires_datetime');
                $table->datetime('created_datetime')->useCurrent();
                $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate();
            });

            $queue = new DatabaseDriver($manager, new DatabaseQueueSchema(), 'main');

            $queue->push(QueueJob::fromPayload(['task' => 'cancel'], 'job-cancel', availableAt: time())->withCancellable());

            $reserved = $queue->reserve();
            self::assertNotNull($reserved);
            self::assertTrue($reserved->isCancellable());
        } finally {
            @unlink($dbFile);
        }
    }
}
