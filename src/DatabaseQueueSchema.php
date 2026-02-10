<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

/**
 * Схема таблицы очереди в БД.
 */
final readonly class DatabaseQueueSchema
{
    public function __construct(
        public string $table = 'queue_jobs',
        public string $idColumn = 'id',
        public string $jobIdColumn = 'job_id',
        public string $payloadColumn = 'payload',
        public string $attemptsColumn = 'attempts',
        public string $priorityColumn = 'priority',
        public string $availableDatetimeColumn = 'available_datetime',
        public string $reservedDatetimeColumn = 'reserved_datetime',
        public string $createdDatetimeColumn = 'created_datetime',
    ) {
    }
}
