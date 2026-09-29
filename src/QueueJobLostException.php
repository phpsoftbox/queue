<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use function sprintf;

/**
 * Задача зарезервирована больше `maxAttempts` раз без результата обработчика: воркер падал посреди неё
 * (OOM, fatal error, kill) или задача выполнялась дольше visibility timeout. `Worker` не запускает её снова,
 * а передаёт это исключение в `FailedJobStoreInterface` и `onFailure`.
 */
final class QueueJobLostException extends QueueException
{
    public static function forJob(QueueJob $job, int $maxAttempts): self
    {
        return new self(sprintf(
            'Queue job "%s" was reserved %d times with max attempts %d without completion: '
            . 'worker lost (crash, OOM, kill) or visibility timeout exceeded.',
            $job->id(),
            $job->attempts(),
            $maxAttempts,
        ));
    }
}
