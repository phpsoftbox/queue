<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

final readonly class DatabaseQueueMutexSchema
{
    public function __construct(
        public string $table = 'queue_mutexes',
        public string $mutexKeyColumn = 'mutex_key',
        public string $ownerJobIdColumn = 'owner_job_id',
        public string $expiresDatetimeColumn = 'expires_datetime',
        public string $createdDatetimeColumn = 'created_datetime',
        public string $updatedDatetimeColumn = 'updated_datetime',
    ) {
    }
}
