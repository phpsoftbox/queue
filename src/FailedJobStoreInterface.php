<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use Throwable;

interface FailedJobStoreInterface
{
    public function store(QueueJob $job, Throwable $exception): void;
}
