<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Event;

use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;
use Throwable;

final readonly class JobAfterEvent
{
    public function __construct(
        public QueueJob $job,
        public ProgressAwareInterface $progress,
        public string $status,
        public ?Throwable $exception = null,
    ) {
    }
}
