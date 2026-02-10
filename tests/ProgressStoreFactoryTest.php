<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use InvalidArgumentException;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Exception\QueryException;
use PhpSoftBox\Queue\DatabaseQueueProgressSchema;
use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\NullProgressStore;
use PhpSoftBox\Queue\ProgressStoreFactory;
use PhpSoftBox\Queue\QueueException;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;

use function bin2hex;
use function get_object_vars;
use function random_bytes;

#[CoversClass(ProgressStoreFactory::class)]
#[CoversMethod(ProgressStoreFactory::class, 'create')]
final class ProgressStoreFactoryTest extends TestCase
{
    /**
     * Проверяет memory/null без создания соединений, независимо от драйвера очереди.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    #[DataProvider('localDrivers')]
    public function createsLocalStoresWithoutDatabase(string $driver, string $class): void
    {
        $connections = $this->createMock(ConnectionManagerInterface::class);
        foreach (['connection', 'read', 'write'] as $method) {
            $connections->expects(self::never())->method($method);
        }
        $factory = new ProgressStoreFactory();
        $config  = ['driver' => 'database', 'progress_driver' => $driver];
        $store   = $factory->create($config, $connections);
        self::assertInstanceOf($class, $store);
        $store->start('job');
        $store->setProcessed('job', 1);
        $store->requestCancellation('job');
        $store->snapshot('job');
        self::assertNotSame($store, $factory->create($config));
    }

    public static function localDrivers(): iterable
    {
        yield [' MeMoRy ', InMemoryProgressStore::class];
        yield [' NULL ', NullProgressStore::class];
    }

    /**
     * Проверяет ошибочные значения драйвера без раскрытия конфигурации и секретов.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    #[DataProvider('invalidDrivers')]
    public function rejectsInvalidDrivers(mixed $driver): void
    {
        try {
            new ProgressStoreFactory()->create(['progress_driver' => $driver, 'password' => 'secret']);
            self::fail('Expected a configuration error.');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('queue.progress_driver', $exception->getMessage());
            self::assertStringContainsString('database, memory, null', $exception->getMessage());
            self::assertStringNotContainsString('secret', $exception->getMessage());
        }
    }

    public static function invalidDrivers(): iterable
    {
        foreach (['', ' ', 'secret', null, 1, false, [], new stdClass()] as $value) {
            yield [$value];
        }
    }

    /**
     * Проверяет выбор database по умолчанию даже для memory-очереди.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    public function requiresConnectionsForDefaultDriver(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ConnectionManagerInterface');
        new ProgressStoreFactory()->create(['driver' => 'memory']);
    }

    /**
     * Проверяет приоритет progress_connection, fallback connection и default без раннего подключения.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    #[DataProvider('connections')]
    public function selectsConnectionLazily(array $config, string $expected): void
    {
        $manager    = $this->createMock(ConnectionManagerInterface::class);
        $connection = $this->createMock(ConnectionInterface::class);
        $manager->expects(self::once())->method('read')->with($expected)->willReturn($connection);
        $manager->expects(self::never())->method('write');
        $connection->expects(self::once())->method('fetchOne')->willReturn(null);
        $connection->method('table')->willReturn('queue_progress');
        $store = new ProgressStoreFactory()->create(['driver' => 'memory', ...$config], $manager);

        self::assertInstanceOf(DatabaseProgressStore::class, $store);
        self::assertNull($store->snapshot('job'));
    }

    public static function connections(): iterable
    {
        yield [[], 'default'];
        yield [['connection' => 'other'], 'other'];
        yield [['progress_driver' => ' DATABASE ', 'connection' => 'other', 'progress_connection' => 'progress'], 'progress'];
        yield [['connection' => 'other', 'progress_connection' => null], 'other'];
    }

    /**
     * Проверяет все переопределения таблицы и колонок на настоящей БД, включая UPSERT отмены.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    public function preservesEverySchemaOverride(): void
    {
        $values = get_object_vars(new DatabaseQueueProgressSchema());
        foreach ($values as $key => $value) {
            $values[$key] = 'custom_' . $value;
        }
        $values['table'] = 'custom_progress_' . bin2hex(random_bytes(6));
        $database        = new ProgressDatabase(new DatabaseQueueProgressSchema(...$values));

        try {
            $store = new ProgressStoreFactory()->create([
                'progress_driver' => 'database', 'progress_connection' => 'progress', 'progress_schema' => $values,
            ], $database->connections);

            $store->requestCancellation('job');
            $store->requestCancellation('job');
            $store->start('job', 2, 5);
            $store->setTotal('job', 10);
            $store->setProcessed('job', 3, 30);
            $store->setMeta('job', ['x' => 1]);
            $store->setStatus('job', 'failed', 'failure');
            $snapshot = $store->snapshot('job');
            self::assertSame(2, $snapshot?->attempt);
            self::assertSame(10, $snapshot?->total);
            self::assertSame(3, $snapshot?->processed);
            self::assertSame(30, $snapshot?->percent);
            self::assertSame(['x' => 1], $snapshot?->meta);
            self::assertSame('failure', $snapshot?->error);
            self::assertNotNull($snapshot?->finishedAt);
            self::assertTrue($store->isCancellationRequested('job'));
        } finally {
            $database->close();
        }
    }

    /**
     * Проверяет, что ошибка БД не заменяет выбранный store на null-реализацию.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    public function propagatesConnectionFailures(): void
    {
        $manager = $this->createMock(ConnectionManagerInterface::class);
        $manager->method('write')->willThrowException(new RuntimeException('Connection refused'));
        $store = new ProgressStoreFactory()->create([], $manager);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Connection refused');
        $store->start('job');
    }

    /**
     * Проверяет, что read-only соединение не отключает прогресс молча.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    public function rejectsReadOnlyWrites(): void
    {
        $manager    = $this->createMock(ConnectionManagerInterface::class);
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->method('isReadOnly')->willReturn(true);
        $connection->expects(self::never())->method('execute');
        $manager->method('write')->willReturn($connection);
        $this->expectException(QueueException::class);
        new ProgressStoreFactory()->create([], $manager)->requestCancellation('job');
    }

    /**
     * Проверяет, что отсутствие таблицы на реальном соединении не отключает хранилище молча.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    public function propagatesMissingTableError(): void
    {
        $database = new ProgressDatabase();

        try {
            $database->connections->write('progress')->schema()->dropIfExists($database->schema->table);
            $store = new ProgressStoreFactory()->create([
                'progress_schema' => ['table' => $database->schema->table],
            ], $database->connections);
            $this->expectException(QueryException::class);
            $store->start('job');
        } finally {
            $database->close();
        }
    }

    /**
     * Проверяет диагностические ошибки некорректных DB-настроек до обращения к соединению.
     * @see ProgressStoreFactory::create()
     */
    #[Test]
    #[DataProvider('invalidDatabaseOptions')]
    public function rejectsInvalidDatabaseOptions(array $config): void
    {
        $manager = $this->createMock(ConnectionManagerInterface::class);
        $manager->expects(self::never())->method('write');
        $this->expectException(InvalidArgumentException::class);
        new ProgressStoreFactory()->create($config, $manager);
    }

    public static function invalidDatabaseOptions(): iterable
    {
        yield [['progress_connection' => ' ']];
        yield [['progress_connection' => []]];
        yield [['progress_schema' => 'secret']];
        yield [['progress_schema' => ['table' => []]]];
        yield [['progress_schema' => ['jobIdColumn' => '']]];
    }
}
