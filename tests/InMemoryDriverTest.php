<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

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
}
