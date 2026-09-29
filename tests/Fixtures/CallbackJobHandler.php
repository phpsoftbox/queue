<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests\Fixtures;

use Closure;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;

final readonly class CallbackJobHandler implements QueueJobHandlerInterface
{
    /**
     * @param Closure(mixed, QueueJob): void $callback
     */
    public function __construct(
        private Closure $callback,
    ) {
    }

    public function handle(mixed $payload, QueueJob $job): void
    {
        ($this->callback)($payload, $job);
    }
}
