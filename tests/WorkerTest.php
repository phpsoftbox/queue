<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\FailedJobStoreInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

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
}
