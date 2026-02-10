<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;
use SplQueue;

final class InMemoryDriver implements QueueInterface
{
    /** @var SplQueue<QueueJob> */
    private SplQueue $queue;

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    public function push(QueueJob $job): void
    {
        $this->queue->enqueue($job);
    }

    public function pop(): ?QueueJob
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        $job = $this->queue->dequeue();

        return $job instanceof QueueJob ? $job : null;
    }

    public function size(): int
    {
        return $this->queue->count();
    }
}
