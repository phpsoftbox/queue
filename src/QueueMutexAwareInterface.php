<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueMutexAwareInterface
{
    public function releaseMutex(QueueJob $job): void;
}
