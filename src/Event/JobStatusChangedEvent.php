<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Event;

use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;
use Throwable;

final readonly class JobStatusChangedEvent
{
    public function __construct(
        public QueueJob $job,
        public ProgressAwareInterface $progress,
        public ?string $previousStatus,
        public string $status,
        public ?Throwable $exception = null,
    ) {
    }
}
