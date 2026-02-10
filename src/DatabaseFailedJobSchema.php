<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

/**
 * Схема таблицы неуспешных заданий.
 */
final readonly class DatabaseFailedJobSchema
{
    public function __construct(
        public string $table = 'queue_failed_jobs',
        public string $idColumn = 'id',
        public string $jobIdColumn = 'job_id',
        public string $payloadColumn = 'payload',
        public string $attemptsColumn = 'attempts',
        public string $exceptionColumn = 'exception',
        public string $failedDatetimeColumn = 'failed_datetime',
    ) {
    }
}
