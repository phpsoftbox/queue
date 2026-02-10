<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;

final readonly class Worker
{
    public function __construct(
        private QueueInterface $queue,
        private int $maxAttempts = 3,
        private ?Closure $onFailure = null,
        private ?FailedJobStoreInterface $failedStore = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param callable(mixed, QueueJob): void $handler
     */
    public function run(callable $handler, int $maxJobs = 0, ?LoggerInterface $logger = null): int
    {
        $logger ??= $this->logger;
        $processed = 0;

        while ($maxJobs === 0 || $processed < $maxJobs) {
            $job = $this->queue->pop();
            if ($job === null) {
                break;
            }

            $processed++;
            $logger?->info('Queue job started', [
                'job_id'   => $job->id(),
                'attempt'  => $job->attempts() + 1,
                'priority' => $job->priority(),
            ]);

            try {
                $handler($job->payload(), $job);
                $logger?->info('Queue job completed', [
                    'job_id'  => $job->id(),
                    'attempt' => $job->attempts() + 1,
                ]);
            } catch (Throwable $exception) {
                $next = $job->withAttempt();
                $logger?->error('Queue job failed', [
                    'job_id'    => $job->id(),
                    'attempt'   => $next->attempts(),
                    'exception' => $exception::class,
                    'message'   => $exception->getMessage(),
                ]);

                if ($next->attempts() < $this->maxAttempts) {
                    $this->queue->push($next);
                    $logger?->warning('Queue job requeued', [
                        'job_id'  => $job->id(),
                        'attempt' => $next->attempts(),
                    ]);
                } elseif ($this->onFailure !== null) {
                    $this->failedStore?->store($next, $exception);
                    ($this->onFailure)($next, $exception);
                } elseif ($this->failedStore !== null) {
                    $this->failedStore->store($next, $exception);
                }
            }
        }

        return $processed;
    }
}
