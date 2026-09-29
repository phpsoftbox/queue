<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(Worker::class)]
#[CoversMethod(Worker::class, 'run')]
final class WorkerShouldStopTest extends TestCase
{
    /**
     * Проверим, что shouldStop проверяется перед каждой задачей: текущая задача доделывается, следующие остаются
     * в очереди.
     *
     * @see Worker::run()
     */
    #[Test]
    public function runStopsBeforeNextJobWhenRequested(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload('first', 'job-1'));
        $queue->push(QueueJob::fromPayload('second', 'job-2'));
        $queue->push(QueueJob::fromPayload('third', 'job-3'));

        $stop    = false;
        $handled = [];

        $processed = new Worker($queue)->run(
            static function (mixed $payload) use (&$handled, &$stop): void {
                $handled[] = $payload;
                // Остановка запрошена посреди задачи.
                $stop = true;
            },
            shouldStop: static function () use (&$stop): bool {
                return $stop;
            },
        );

        self::assertSame(1, $processed);
        self::assertSame(['first'], $handled);
        self::assertSame(2, $queue->size());
    }
}
