<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\ProgressReporter;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, DataProvider, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressReporter::class)]
#[CoversMethod(ProgressReporter::class, 'setProcessed')]
final class ProgressReporterStoresTest extends TestCase
{
    private ?ProgressDatabase $database = null;

    protected function tearDown(): void
    {
        $this->database?->close();
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
        yield 'memory' => ['memory'];
        yield 'database' => ['database'];
    }

    /**
     * Проверяет публикацию по шагу, обязательные 100% и отсутствие вычисления процентов в store.
     * @see ProgressReporter::setProcessed()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function flushesByStepAndAlwaysAtCompletion(string $driver): void
    {
        $store    = $this->store($driver);
        $reporter = new ProgressReporter($store, 'job', stepPercent: 30);

        $reporter->setTotal(100);
        $reporter->setProcessed(29);
        self::assertSame(0, $store->snapshot('job')?->processed);
        $reporter->increment();
        self::assertSame(30, $store->snapshot('job')?->percent);
        $reporter->setProcessed(95);
        self::assertSame(95, $store->snapshot('job')?->percent);
        $reporter->setProcessed(110);
        self::assertSame(100, $store->snapshot('job')?->percent);
        self::assertSame(110, $store->snapshot('job')?->processed);
    }

    /**
     * Проверяет запись каждого обновления при неизвестном или нулевом total без SQL NULL в percent.
     * @see ProgressReporter::setTotal()
     */
    #[Test]
    #[DataProvider('unknownTotals')]
    public function writesCountsWithoutKnownTotal(string $driver, ?int $total): void
    {
        $store    = $this->store($driver);
        $reporter = new ProgressReporter($store, 'job', stepPercent: 100);

        $reporter->setTotal($total);
        $reporter->increment(3);
        self::assertSame(3, $store->snapshot('job')?->processed);
        self::assertSame(0, $store->snapshot('job')?->percent);
        $reporter->increment(2);
        self::assertSame(5, $store->snapshot('job')?->processed);
    }

    public static function unknownTotals(): iterable
    {
        foreach (['memory', 'database'] as $driver) {
            yield [$driver, null];
            yield [$driver, 0];
        }
    }

    /**
     * Проверяет новый reporter на retry: счётчики сбрасываются, запрос отмены остаётся доступен.
     * @see ProgressReporter::__construct()
     */
    #[Test]
    #[DataProvider('drivers')]
    public function resetsCountersForRetryButKeepsCancellation(string $driver): void
    {
        $store = $this->store($driver);
        $first = new ProgressReporter($store, 'job');

        $first->setTotal(10);
        $first->increment(8);
        $first->setMeta(['source' => 'test']);
        $store->requestCancellation('job');
        $next = new ProgressReporter($store, 'job', attempt: 2);
        self::assertSame(0, $store->snapshot('job')?->processed);
        self::assertSame(2, $store->snapshot('job')?->attempt);
        self::assertSame(['source' => 'test'], $store->snapshot('job')?->meta);
        self::assertTrue($next->isCancellationRequested());
    }
}
