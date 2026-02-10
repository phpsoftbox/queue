<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexConflictException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function time;

#[CoversClass(InMemoryDriver::class)]
final class InMemoryDriverTest extends TestCase
{
    /**
     * Проверяет, что InMemory-очередь работает по FIFO.
     */
    #[Test]
    public function testQueueUsesFifoOrder(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('first', 'payload-1'));
        $queue->push(new QueueJob('second', 'payload-2'));

        $this->assertSame(2, $queue->size());

        $first  = $queue->pop();
        $second = $queue->pop();

        $this->assertSame('first', $first?->id());
        $this->assertSame('second', $second?->id());
        $this->assertSame(0, $queue->size());
    }

    /**
     * Проверяет, что mutex блокирует добавление второй задачи с тем же ключом.
     */
    #[Test]
    public function testQueueMutexPreventsParallelJobPush(): void
    {
        $queue = new InMemoryDriver();

        $first = QueueJob::fromPayload(['a' => 1], 'job-1')->withMutex('mutex:company:10');
        $queue->push($first);

        $this->expectException(QueueMutexConflictException::class);
        $queue->push(QueueJob::fromPayload(['a' => 2], 'job-2')->withMutex('mutex:company:10'));
    }

    /**
     * Проверяет, что pop() пропускает отложенные задачи и берёт доступную сейчас.
     */
    #[Test]
    public function testQueueSkipsDelayedJobsUntilTheyAreAvailable(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(QueueJob::fromPayload(['a' => 1], 'job-delayed', availableAt: time() + 60));
        $queue->push(QueueJob::fromPayload(['a' => 2], 'job-now', availableAt: time()));

        $first = $queue->pop();
        self::assertSame('job-now', $first?->id());
    }
}
