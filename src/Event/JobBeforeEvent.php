<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Event;

use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;

final readonly class JobBeforeEvent
{
    public function __construct(
        public QueueJob $job,
        public ProgressAwareInterface $progress,
    ) {
    }
}
