<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\NullProgressStore;
use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexAwareInterface;
use PhpSoftBox\Queue\QueueReservationAwareInterface;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, DataProvider, Test};
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Worker::class)]
#[CoversMethod(Worker::class, 'run')]
final class WorkerProgressStoresTest extends TestCase
{
    /**
     * Проверяет success, успешный retry и исчерпание попыток с настоящим memory-store.
     * @see Worker::run()
     */
    #[Test]
    #[DataProvider('outcomes')]
    public function recordsWorkerOutcome(int $failures, int $attempts, string $status): void
    {
        $queue = new InMemoryDriver();
        $store = new InMemoryProgressStore();
        $queue->push(new QueueJob('job', null));
        $calls  = 0;
        $worker = new Worker($queue, maxAttempts: 2, progressStore: $store);

        self::assertSame($attempts, $worker->run(static function (mixed $payload, QueueJob $job, ProgressAwareInterface $progress) use (&$calls, $failures): void {
            $calls++;
            $progress->setTotal(10);
            $progress->increment(10);
            if ($calls <= $failures) {
                throw new RuntimeException('failure');
            }
        }));
        self::assertSame($status, $store->snapshot('job')?->status);
        self::assertSame($attempts, $store->snapshot('job')?->attempt);
        self::assertSame($status === 'failed' ? 'failure' : null, $store->snapshot('job')?->error);
        self::assertSame(100, $store->snapshot('job')?->percent);
        self::assertSame(0, $queue->size());
    }

    public static function outcomes(): iterable
    {
        yield 'success' => [0, 1, 'completed'];
        yield 'retry-success' => [1, 2, 'completed'];
        yield 'failed' => [2, 2, 'failed'];
    }

    /**
     * Проверяет отмену до старта handler и реальное освобождение mutex в memory-очереди.
     * @see Worker::run()
     */
    #[Test]
    public function cancelsBeforeHandlingAndReleasesMutex(): void
    {
        $queue = new InMemoryDriver();
        $store = new InMemoryProgressStore();
        $queue->push(new QueueJob('job', null)->withCancellable()->withMutex('import'));
        $store->requestCancellation('job');
        $handled = false;
        new Worker($queue, progressStore: $store)->run(static function () use (&$handled): void {
            $handled = true;
        });
        self::assertFalse($handled);
        self::assertSame('cancelled', $store->snapshot('job')?->status);
        self::assertSame(0, $queue->size());
        $queue->push(new QueueJob('next', null)->withMutex('import'));
        self::assertSame(1, $queue->size());
    }

    /**
     * Проверяет подтверждение отменённой задачи и освобождение mutex у reservable-очереди.
     * @see Worker::run()
     */
    #[Test]
    public function acknowledgesCancelledReservedJob(): void
    {
        $queue = $this->createMockForIntersectionOfInterfaces([QueueReservationAwareInterface::class, QueueMutexAwareInterface::class]);
        $job   = new QueueJob('job', null)->withCancellable()->withMutex('import');

        $queue->expects(self::once())->method('reserve')->willReturn($job);
        $queue->expects(self::once())->method('acknowledge')->with($job);
        $queue->expects(self::once())->method('releaseMutex')->with($job);
        $queue->expects(self::never())->method('release');
        $store = new InMemoryProgressStore();

        $store->requestCancellation('job');
        new Worker($queue, progressStore: $store)->run(static function (): void {
            self::fail('Cancelled handler must not run.');
        }, maxJobs: 1);
        self::assertSame('cancelled', $store->snapshot('job')?->status);
    }

    /**
     * Проверяет, что флаг отмены не отменяет задания без withCancellable.
     * @see Worker::run()
     */
    #[Test]
    public function doesNotCancelNonCancellableJobs(): void
    {
        $queue = new InMemoryDriver();
        $store = new InMemoryProgressStore();
        $queue->push(new QueueJob('job', null));
        $store->requestCancellation('job');
        $handled = false;
        new Worker($queue, progressStore: $store)->run(static function () use (&$handled): void {
            $handled = true;
        });
        self::assertTrue($handled);
        self::assertSame('completed', $store->snapshot('job')?->status);
    }

    /**
     * Проверяет сохранение отмены, запрошенной обработчиком, до следующей попытки worker.
     * @see Worker::run()
     */
    #[Test]
    public function cancellationSurvivesWorkerRetry(): void
    {
        $queue = new InMemoryDriver();
        $store = new InMemoryProgressStore();
        $queue->push(new QueueJob('job', null)->withCancellable());
        $calls = 0;
        new Worker($queue, progressStore: $store)->run(static function () use ($store, &$calls): void {
            $calls++;
            $store->requestCancellation('job');

            throw new RuntimeException('retry');
        });
        self::assertSame(1, $calls);
        self::assertSame(2, $store->snapshot('job')?->attempt);
        self::assertSame('cancelled', $store->snapshot('job')?->status);
    }

    /**
     * Проверяет выполнение handler с null-store и с прежним Worker без хранилища.
     * @see Worker::run()
     */
    #[Test]
    #[DataProvider('disabledModes')]
    public function keepsDisabledProgressCompatible(bool $withStore): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job', null)->withCancellable());
        $handled = false;
        new Worker($queue, progressStore: $withStore ? new NullProgressStore() : null)->run(
            static function (mixed $payload, QueueJob $job, ProgressAwareInterface $progress) use (&$handled): void {
                $handled = true;
                $progress->setTotal(null);
                $progress->increment();
                self::assertFalse($progress->isCancellationRequested());
            },
        );
        self::assertTrue($handled);
        self::assertSame(0, $queue->size());
    }

    public static function disabledModes(): iterable
    {
        yield [false];
        yield [true];
    }
}
