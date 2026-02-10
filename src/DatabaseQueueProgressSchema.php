<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

final readonly class DatabaseQueueProgressSchema
{
    public function __construct(
        public string $table = 'queue_progress',
        public string $jobIdColumn = 'job_id',
        public string $statusColumn = 'status',
        public string $totalColumn = 'total',
        public string $processedColumn = 'processed',
        public string $percentColumn = 'percent',
        public string $stepPercentColumn = 'step_percent',
        public string $attemptColumn = 'attempt',
        public string $errorColumn = 'error',
        public string $metaColumn = 'meta',
        public string $cancelRequestedDatetimeColumn = 'cancel_requested_datetime',
        public string $startedDatetimeColumn = 'started_datetime',
        public string $finishedDatetimeColumn = 'finished_datetime',
        public string $createdDatetimeColumn = 'created_datetime',
        public string $updatedDatetimeColumn = 'updated_datetime',
    ) {
    }
}
