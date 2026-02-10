<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueJobHandlerInterface
{
    public function handle(mixed $payload, QueueJob $job): void;
}
