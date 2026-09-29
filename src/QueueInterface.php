<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueInterface
{
    public function push(QueueJob $job): void;

    /**
     * Забирает задачу из очереди, увеличив её `attempts` на 1 (номер текущей попытки).
     */
    public function pop(): ?QueueJob;

    public function size(): int;
}
