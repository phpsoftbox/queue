<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use DateTimeImmutable;
use PhpSoftBox\Clock\Clock;
use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueProgressStatus;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(DatabaseProgressStore::class)]
#[CoversClass(InMemoryProgressStore::class)]
#[CoversMethod(DatabaseProgressStore::class, 'start')]
#[CoversMethod(InMemoryProgressStore::class, 'start')]
final class ProgressStoreContractTest extends TestCase
{
    private ?ProgressDatabase $database = null;

    protected function setUp(): void
    {
        Clock::freeze(new DateTimeImmutable('2026-09-21 12:00:00'));
    }

    protected function tearDown(): void
    {
        try {
            $this->database?->close();
        } finally {
            Clock::reset();
        }
    }

    private function store(string $driver): ProgressStoreInterface
    {
        if ($driver === 'memory') {
            return new InMemoryProgressStore();
        }
        $this->database = new ProgressDatabase();

        return new DatabaseProgressStore($this->database->connections, $this->database->schema, 'progress');
    }

    public static function drivers(): iterable
    {
        yield 'database' => ['database'];
        yield 'memory' => ['memory'];
    }

    /**
     * Проверяет начальное состояние и нормализацию идентификатора и номера попытки.
     * @see ProgressStoreInterface::start()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function startsWithQueuedSnapshot(string $driver): void
    {
        $store = $this->store($driver);
        self::assertNull($store->snapshot('job'));
        $store->start(' job ', 0, -1);
        self::assertSame([
            'status'     => 'queued', 'attempt' => 1, 'total' => null, 'processed' => 0,
            'percent'    => 0, 'error' => null, 'meta' => [], 'cancel_requested_at' => null,
            'started_at' => '2026-09-21 12:00:00', 'finished_at' => null,
            'updated_at' => '2026-09-21 12:00:00',
        ], $store->snapshot('job')?->toArray());
    }

    /**
     * Проверяет отсутствие создания записи обычными setter-ами и игнорирование пустого jobId.
     * @see ProgressStoreInterface::setProcessed()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function ignoresMissingJobsAndBlankIds(string $driver): void
    {
        $store = $this->store($driver);
        foreach (['missing', " \t\n"] as $id) {
            $store->setTotal($id, 12);
            $store->setProcessed($id, 2, 10);
            $store->setStatus($id, 'processing');
            $store->setMeta($id, ['x' => 1]);
            self::assertNull($store->snapshot($id));
            self::assertFalse($store->isCancellationRequested($id));
        }
        $store->start(' ');
        self::assertFalse($store->requestCancellation(' '));
        self::assertNull($store->snapshot(' '));
    }

    /**
     * Проверяет нормализацию чисел без вычисления процентов и ограничения их сверху.
     * @see ProgressStoreInterface::setTotal()
     * @see ProgressStoreInterface::setProcessed()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function normalizesCountsAndUnknownPercent(string $driver): void
    {
        $store = $this->store($driver);
        $store->start('job');
        foreach ([null, -1, 0, 10] as $total) {
            $store->setTotal('job', $total);
            self::assertSame($total === -1 ? null : $total, $store->snapshot('job')?->total);
        }
        foreach ([null, -1, 0, 175] as $percent) {
            $store->setProcessed('job', -3, $percent);
            self::assertSame(0, $store->snapshot('job')?->processed);
            self::assertSame($percent === null || $percent < 0 ? 0 : $percent, $store->snapshot('job')?->percent);
        }
        $store->setProcessed('job', 200, 3);
        self::assertSame(200, $store->snapshot('job')?->processed);
        self::assertSame(3, $store->snapshot('job')?->percent);
    }

    /**
     * Проверяет повторную отмену в ту же секунду без дублей и сохранение флага после старта.
     * @see ProgressStoreInterface::requestCancellation()
     * @see ProgressStoreInterface::start()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function preservesRepeatedCancellationBeforeStart(string $driver): void
    {
        $store = $this->store($driver);
        self::assertTrue($store->requestCancellation(' job '));
        self::assertTrue($store->requestCancellation('job'));
        self::assertNull($store->snapshot('job')?->startedAt);
        self::assertSame('queued', $store->snapshot('job')?->status);
        Clock::travel(60);
        $store->start('job', 2);
        self::assertTrue($store->isCancellationRequested('job'));
        self::assertSame('2026-09-21 12:00:00', $store->snapshot('job')?->cancelRequestedAt);
        self::assertSame('2026-09-21 12:01:00', $store->snapshot('job')?->startedAt);
    }

    /**
     * Проверяет сброс результата при retry с сохранением метаданных и запроса отмены.
     * @see ProgressStoreInterface::start()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function restartsWithoutLosingMetadataOrCancellation(string $driver): void
    {
        $store = $this->store($driver);
        $store->start('job');
        $store->setTotal('job', 10);
        $store->setProcessed('job', 9, 90);
        $store->setMeta('job', ['source' => 'import']);
        $store->setStatus('job', 'failed', 'failure');
        $store->requestCancellation('job');
        Clock::travel(10);
        $store->start('job', 3);
        $snapshot = $store->snapshot('job');
        self::assertSame('queued', $snapshot?->status);
        self::assertSame(3, $snapshot?->attempt);
        self::assertNull($snapshot?->total);
        self::assertSame(0, $snapshot?->processed);
        self::assertSame(0, $snapshot?->percent);
        self::assertNull($snapshot?->error);
        self::assertNull($snapshot?->finishedAt);
        self::assertSame(['source' => 'import'], $snapshot?->meta);
        self::assertTrue($store->isCancellationRequested('job'));
    }

    /**
     * Проверяет JSON-копирование и поверхностное объединение meta без изменения старого snapshot.
     * @see ProgressStoreInterface::setMeta()
     * @see ProgressStoreInterface::snapshot()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function snapshotsAreDetachedAndJobsIndependent(string $driver): void
    {
        $store = $this->store($driver);
        $store->start('a');
        $store->start('b');
        $object = (object) ['value' => 1];
        $store->setMeta('a', ['object' => $object, 'keep' => 1, 'nested' => ['a' => 1]]);
        $before        = $store->snapshot('a');
        $object->value = 9;
        Clock::travel(10);
        $store->setMeta('a', ['nested' => ['b' => 2]]);
        $store->setProcessed('a', 2, 50);
        self::assertEquals(['object' => ['value' => 1], 'keep' => 1, 'nested' => ['a' => 1]], $before?->meta);
        self::assertSame(0, $before?->processed);
        self::assertEquals(['object' => ['value' => 1], 'keep' => 1, 'nested' => ['b' => 2]], $store->snapshot('a')?->meta);
        self::assertSame([], $store->snapshot('b')?->meta);
        self::assertSame(0, $store->snapshot('b')?->processed);
        self::assertSame('2026-09-21 12:00:00', $before?->updatedAt);
        self::assertSame('2026-09-21 12:00:10', $store->snapshot('a')?->updatedAt);
    }

    /**
     * Проверяет сохранение terminal-статусов от completed и очистку finishedAt при новом статусе.
     * @see ProgressStoreInterface::setStatus()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function respectsTerminalStatusGuards(string $driver): void
    {
        $store = $this->store($driver);
        foreach ([QueueProgressStatus::FAILED, QueueProgressStatus::CANCELLED, QueueProgressStatus::COMPLETED] as $status) {
            $store->start($status);
            $store->setStatus($status, ' ' . $status . ' ', ' error ');
            $before = $store->snapshot($status);
            self::assertSame('2026-09-21 12:00:00', $before?->finishedAt);
            self::assertSame('error', $before?->error);
            if ($status !== QueueProgressStatus::COMPLETED) {
                $store->setStatus($status, QueueProgressStatus::COMPLETED);
                self::assertEquals($before, $store->snapshot($status));
            }
            $store->setStatus($status, ' ');
            self::assertEquals($before, $store->snapshot($status));
            $store->setStatus($status, 'custom');
            self::assertNull($store->snapshot($status)?->finishedAt);
            self::assertNull($store->snapshot($status)?->error);
        }
    }

    /**
     * Проверяет одинаковую ошибку сериализации meta без повреждения сохранённого состояния.
     * @see ProgressStoreInterface::setMeta()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function rejectsInvalidJsonMetadata(string $driver): void
    {
        $store = $this->store($driver);
        $store->start('job');
        $this->expectException(QueueException::class);
        try {
            $store->setMeta('job', ['bad' => "\xFF"]);
        } finally {
            self::assertSame([], $store->snapshot('job')?->meta);
        }
    }

    /**
     * Проверяет, что отмена готового задания обновляет только отметки, а не его результат.
     * @see ProgressStoreInterface::requestCancellation()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function cancellationDoesNotResetExistingProgress(string $driver): void
    {
        $store = $this->store($driver);
        $store->start('job', 3, 5);
        $store->setTotal('job', 10);
        $store->setProcessed('job', 8, 80);
        $store->setMeta('job', ['x' => 1]);
        $store->setStatus('job', 'failed', 'error');
        $before = $store->snapshot('job')?->toArray();
        Clock::travel(60);
        self::assertTrue($store->requestCancellation('job'));
        self::assertTrue($store->requestCancellation('job'));
        $after = $store->snapshot('job')?->toArray();
        self::assertSame('2026-09-21 12:01:00', $after['cancel_requested_at']);
        self::assertSame('2026-09-21 12:01:00', $after['updated_at']);
        unset($before['cancel_requested_at'], $before['updated_at'], $after['cancel_requested_at'], $after['updated_at']);
        self::assertSame($before, $after);
    }
}
