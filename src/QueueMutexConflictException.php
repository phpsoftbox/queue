<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use Throwable;

final class QueueMutexConflictException extends QueueException
{
    public function __construct(
        private readonly string $mutexKey,
        string $message = 'Queue mutex is already acquired by another job.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function mutexKey(): string
    {
        return $this->mutexKey;
    }
}
