<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\Event\JobAfterEvent;
use PhpSoftBox\Queue\Event\JobBeforeEvent;
use PhpSoftBox\Queue\Event\JobStatusChangedEvent;
use PhpSoftBox\Queue\FailedJobStoreInterface;
use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use PhpSoftBox\Queue\QueueProgressStatus;
use PhpSoftBox\Queue\QueueReservationAwareInterface;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Throwable;

use function array_shift;
use function count;

#[CoversClass(Worker::class)]
final class WorkerTest extends TestCase
{
    /**
     * Проверяет, что воркер обрабатывает задачи из очереди.
     */
    #[Test]
    public function testWorkerProcessesJobs(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job-1', 'first'));
        $queue->push(new QueueJob('job-2', 'second'));

        $handled = [];
        $worker  = new Worker($queue);

        $processed = $worker->run(function (mixed $payload) use (&$handled): void {
            $handled[] = $payload;
        });

        $this->assertSame(2, $processed);
        $this->assertSame(['first', 'second'], $handled);
    }

    /**
     * Проверяет повторные попытки и вызов обработчика фатальной ошибки.
     */
    #[Test]
    public function testWorkerRetriesAndReportsFailure(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job-1', 'payload'));

        $failures = [];
        $worker   = new Worker($queue, maxAttempts: 2, onFailure: function (QueueJob $job, RuntimeException $exception) use (&$failures): void {
            $failures[] = ['id' => $job->id(), 'attempts' => $job->attempts(), 'message' => $exception->getMessage()];
        });

        $processed = $worker->run(function (): void {
            throw new RuntimeException('boom');
        });

        $this->assertSame(2, $processed);
        $this->assertSame([['id' => 'job-1', 'attempts' => 2, 'message' => 'boom']], $failures);
    }

    /**
     * Проверяет запись задания в хранилище неуспешных задач.
     */
    #[Test]
    public function testWorkerStoresFailedJob(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job-1', 'payload'));

        $store = new class () implements FailedJobStoreInterface {
            private array $stored = [];

            public function getStored(): array
            {
                return $this->stored;
            }

            public function store(QueueJob $job, Throwable $exception): void
            {
                $this->stored[] = [
                    'id'       => $job->id(),
                    'attempts' => $job->attempts(),
                    'message'  => $exception->getMessage(),
                ];
            }
        };

        $worker = new Worker($queue, maxAttempts: 1, failedStore: $store);

        $worker->run(function (): void {
            throw new RuntimeException('fail');
        });

        $stored = $store->getStored();

        $this->assertSame([['id' => 'job-1', 'attempts' => 1, 'message' => 'fail']], $stored);
    }

    #[Test]
    public function testWorkerReleasesMutexAfterSuccessfulHandling(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload(['id' => 1], 'job-1')->withMutex('import:company:15'));

        $worker = new Worker($queue);

        $worker->run(static function (): void {
        });

        $queue->push(QueueJob::fromPayload(['id' => 2], 'job-2')->withMutex('import:company:15'));

        self::assertSame(1, $queue->size());
    }

    /**
     * Проверяет передачу progress reporter в handler с третьим аргументом.
     */
    #[Test]
    public function testWorkerPassesProgressReporterToHandlerWhenSupported(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload(['id' => 1], 'job-1'));

        $store = new class () implements ProgressStoreInterface {
            /** @var list<string> */
            public array $statuses = [];

            public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
            {
            }

            public function setTotal(string $jobId, ?int $total): void
            {
            }

            public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
            {
            }

            public function setStatus(string $jobId, string $status, ?string $error = null): void
            {
                $this->statuses[] = $status;
            }

            public function setMeta(string $jobId, array $meta): void
            {
            }

            public function requestCancellation(string $jobId): bool
            {
                return false;
            }

            public function isCancellationRequested(string $jobId): bool
            {
                return false;
            }

            public function snapshot(string $jobId): ?QueueProgressSnapshot
            {
                return null;
            }
        };

        $worker = new Worker($queue, progressStore: $store);

        $receivedReporter = false;
        $processed        = $worker->run(function (mixed $_payload, QueueJob $_job, ProgressAwareInterface $progress) use (&$receivedReporter): void {
            $receivedReporter = true;
            $progress->setTotal(10);
            $progress->increment();
        });

        self::assertSame(1, $processed);
        self::assertTrue($receivedReporter);
        self::assertContains(QueueProgressStatus::PROCESSING, $store->statuses);
        self::assertContains(QueueProgressStatus::COMPLETED, $store->statuses);
    }

    /**
     * Проверяет отмену job с флагом is_cancellable до запуска handler.
     */
    #[Test]
    public function testWorkerCancelsCancellableJobBeforeRunWhenRequested(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(
            QueueJob::fromPayload(['id' => 1], 'job-1')
                ->withMutex('import:company:15')
                ->withCancellable(),
        );

        $store = new class () implements ProgressStoreInterface {
            /** @var list<string> */
            public array $statuses = [];

            public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
            {
            }

            public function setTotal(string $jobId, ?int $total): void
            {
            }

            public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
            {
            }

            public function setStatus(string $jobId, string $status, ?string $error = null): void
            {
                $this->statuses[] = $status;
            }

            public function setMeta(string $jobId, array $meta): void
            {
            }

            public function requestCancellation(string $jobId): bool
            {
                return true;
            }

            public function isCancellationRequested(string $jobId): bool
            {
                return true;
            }

            public function snapshot(string $jobId): ?QueueProgressSnapshot
            {
                return null;
            }
        };

        $handled = false;
        $worker  = new Worker($queue, progressStore: $store);

        $worker->run(function () use (&$handled): void {
            $handled = true;
        });

        self::assertFalse($handled);
        self::assertContains(QueueProgressStatus::CANCELLED, $store->statuses);

        $queue->push(
            QueueJob::fromPayload(['id' => 2], 'job-2')
                ->withMutex('import:company:15')
                ->withCancellable(),
        );
        self::assertSame(1, $queue->size());
    }

    /**
     * Проверяет dispatch lifecycle-событий воркера в процессе выполнения задачи.
     */
    #[Test]
    public function testWorkerDispatchesLifecycleEvents(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload(['id' => 1], 'job-1'));

        $dispatcher = new class () implements EventDispatcherInterface {
            /** @var list<object> */
            public array $events = [];

            public function dispatch(object $event): object
            {
                $this->events[] = $event;

                return $event;
            }
        };

        $worker = new Worker($queue, events: $dispatcher);

        $worker->run(static function (): void {
        });

        self::assertCount(4, $dispatcher->events);
        self::assertInstanceOf(JobStatusChangedEvent::class, $dispatcher->events[0]);
        self::assertInstanceOf(JobBeforeEvent::class, $dispatcher->events[1]);
        self::assertInstanceOf(JobStatusChangedEvent::class, $dispatcher->events[2]);
        self::assertInstanceOf(JobAfterEvent::class, $dispatcher->events[3]);

        /** @var JobStatusChangedEvent $processing */
        $processing = $dispatcher->events[0];
        /** @var JobStatusChangedEvent $completed */
        $completed = $dispatcher->events[2];
        /** @var JobAfterEvent $after */
        $after = $dispatcher->events[3];

        self::assertSame(QueueProgressStatus::QUEUED, $processing->previousStatus);
        self::assertSame(QueueProgressStatus::PROCESSING, $processing->status);
        self::assertSame(QueueProgressStatus::PROCESSING, $completed->previousStatus);
        self::assertSame(QueueProgressStatus::COMPLETED, $completed->status);
        self::assertSame(QueueProgressStatus::COMPLETED, $after->status);
        self::assertNull($after->exception);
    }

    #[Test]
    public function testWorkerUsesReleaseAndAckForReservableQueue(): void
    {
        $job = QueueJob::fromPayload(['id' => 1], 'job-1');

        $queue = new class ($job) implements QueueInterface, QueueReservationAwareInterface {
            /** @var list<QueueJob> */
            private array $jobs;

            /** @var list<QueueJob> */
            public array $released = [];

            /** @var list<string> */
            public array $acked = [];

            public function __construct(QueueJob $job)
            {
                $this->jobs = [$job];
            }

            public function push(QueueJob $job): void
            {
                $this->jobs[] = $job;
            }

            public function pop(): ?QueueJob
            {
                return null;
            }

            public function size(): int
            {
                return count($this->jobs);
            }

            public function reserve(): ?QueueJob
            {
                return array_shift($this->jobs);
            }

            public function acknowledge(QueueJob $job): void
            {
                $this->acked[] = $job->id();
            }

            public function release(QueueJob $job, int $delaySeconds = 0): void
            {
                $this->released[] = $job;
                $this->jobs[]     = $job;
            }
        };

        $attempts = 0;
        $worker   = new Worker($queue, maxAttempts: 3);

        $processed = $worker->run(function () use (&$attempts): void {
            $attempts++;
            if ($attempts === 1) {
                throw new RuntimeException('fail once');
            }
        });

        self::assertSame(2, $processed);
        self::assertCount(1, $queue->released);
        self::assertSame(1, $queue->released[0]->attempts());
        self::assertSame(['job-1'], $queue->acked);
    }

    #[Test]
    public function testWorkerAppliesRetryDelayForNonReservableQueue(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload(['id' => 1], 'job-1'));

        $worker = new Worker(
            queue: $queue,
            maxAttempts: 2,
            retryDelaySeconds: 30,
        );

        $processed = $worker->run(static function (): void {
            throw new RuntimeException('boom');
        });

        self::assertSame(1, $processed);
        self::assertSame(1, $queue->size());
        self::assertNull($queue->pop(), 'Задача должна быть отложена и недоступна сразу после ретрая.');
    }
}
