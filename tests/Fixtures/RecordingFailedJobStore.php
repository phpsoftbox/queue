<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests\Fixtures;

use PhpSoftBox\Queue\FailedJobStoreInterface;
use PhpSoftBox\Queue\QueueJob;
use Throwable;

final class RecordingFailedJobStore implements FailedJobStoreInterface
{
    /**
     * @var list<array{job: QueueJob, exception: Throwable}>
     */
    public array $stored = [];

    public function store(QueueJob $job, Throwable $exception): void
    {
        $this->stored[] = ['job' => $job, 'exception' => $exception];
    }
}
