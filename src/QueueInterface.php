<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueInterface
{
    public function push(QueueJob $job): void;

    public function pop(): ?QueueJob;

    public function size(): int;
}
