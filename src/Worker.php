<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use Closure;
use PhpSoftBox\Queue\Event\JobAfterEvent;
use PhpSoftBox\Queue\Event\JobBeforeEvent;
use PhpSoftBox\Queue\Event\JobStatusChangedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

use function is_array;
use function is_int;
use function is_object;
use function is_string;
use function max;
use function str_contains;

final readonly class Worker
{
    public function __construct(
        private QueueInterface $queue,
        private int $maxAttempts = 3,
        private ?Closure $onFailure = null,
        private ?FailedJobStoreInterface $failedStore = null,
        private ?LoggerInterface $logger = null,
        private ?ProgressStoreInterface $progressStore = null,
        private ?EventDispatcherInterface $events = null,
        private int $progressStepPercent = 1,
        /**
         * @var null|Closure(QueueJob,Throwable,int):int
         */
        private ?Closure $retryDelayResolver = null,
        private int $retryDelaySeconds = 0,
    ) {
    }

    /**
     * @param callable(mixed, QueueJob, ProgressAwareInterface): void $handler
     */
    public function run(callable $handler, int $maxJobs = 0, ?LoggerInterface $logger = null): int
    {
        $logger ??= $this->logger;
        $processed = 0;

        while ($maxJobs === 0 || $processed < $maxJobs) {
            $job = $this->pullJob();
            if ($job === null) {
                break;
            }

            $processed++;
            $progress = $this->createProgress($job);
            if ($job->isCancellable() && $progress->isCancellationRequested()) {
                $status = QueueProgressStatus::CANCELLED;
                $progress->setStatus($status, 'Job отменён до запуска.');
                $this->dispatchStatusChange($job, $progress, QueueProgressStatus::QUEUED, $status);
                try {
                    $this->acknowledgeReservedJob($job);
                } finally {
                    $this->releaseMutexSafely($job, $logger);
                }
                $logger?->warning('Queue job cancelled before handling', [
                    'job_id' => $job->id(),
                ]);
                $this->dispatchEvent(new JobAfterEvent($job, $progress, $status));
                continue;
            }

            $status = QueueProgressStatus::PROCESSING;
            $progress->setStatus($status);
            $this->dispatchStatusChange($job, $progress, QueueProgressStatus::QUEUED, $status);
            $logger?->info('Queue job started', [
                'job_id'   => $job->id(),
                'attempt'  => $job->attempts() + 1,
                'priority' => $job->priority(),
            ]);
            $this->dispatchEvent(new JobBeforeEvent($job, $progress));

            $lastException = null;
            try {
                $this->invokeHandler($handler, $job, $progress);

                $status = QueueProgressStatus::COMPLETED;
                $progress->setStatus($status);
                $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status);
                try {
                    $this->acknowledgeReservedJob($job);
                } finally {
                    $this->releaseMutexSafely($job, $logger);
                }

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
                $lastException = $exception;

                if ($next->attempts() < $this->maxAttempts) {
                    $status = QueueProgressStatus::RETRYING;
                    $progress->setStatus($status, $exception->getMessage());
                    $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status, $exception);
                    $delaySeconds = $this->resolveRetryDelay($next, $exception);
                    $this->retryJob($next, $delaySeconds);
                    $logger?->warning('Queue job requeued', [
                        'job_id'        => $job->id(),
                        'attempt'       => $next->attempts(),
                        'delay_seconds' => $delaySeconds,
                    ]);
                } else {
                    try {
                        $status = QueueProgressStatus::FAILED;
                        $progress->setStatus($status, $exception->getMessage());
                        $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status, $exception);
                        if ($this->onFailure !== null) {
                            $this->failedStore?->store($next, $exception);
                            ($this->onFailure)($next, $exception);
                        } elseif ($this->failedStore !== null) {
                            $this->failedStore->store($next, $exception);
                        }
                    } finally {
                        try {
                            $this->acknowledgeReservedJob($job);
                        } finally {
                            $this->releaseMutexSafely($next, $logger);
                        }
                    }
                }
            } finally {
                $this->dispatchEvent(new JobAfterEvent($job, $progress, $status, $lastException));
            }
        }

        return $processed;
    }

    private function pullJob(): ?QueueJob
    {
        if ($this->queue instanceof QueueReservationAwareInterface) {
            return $this->queue->reserve();
        }

        return $this->queue->pop();
    }

    private function acknowledgeReservedJob(QueueJob $job): void
    {
        if (!$this->queue instanceof QueueReservationAwareInterface) {
            return;
        }

        $this->queue->acknowledge($job);
    }

    private function retryJob(QueueJob $job, int $delaySeconds): void
    {
        if ($this->queue instanceof QueueReservationAwareInterface) {
            $this->queue->release($job, $delaySeconds);

            return;
        }

        if ($delaySeconds > 0) {
            $job = $job->withDelay($delaySeconds);
        }

        $this->queue->push($job);
    }

    private function resolveRetryDelay(QueueJob $job, Throwable $exception): int
    {
        if ($this->retryDelayResolver instanceof Closure) {
            $resolved = ($this->retryDelayResolver)($job, $exception, $job->attempts());

            if (!is_int($resolved) || $resolved <= 0) {
                return 0;
            }

            return $resolved;
        }

        return max(0, $this->retryDelaySeconds);
    }

    private function releaseMutexSafely(QueueJob $job, ?LoggerInterface $logger): void
    {
        if (!$this->queue instanceof QueueMutexAwareInterface) {
            return;
        }

        try {
            $this->queue->releaseMutex($job);
        } catch (Throwable $exception) {
            $logger?->warning('Queue mutex release failed', [
                'job_id'    => $job->id(),
                'mutex_key' => $job->mutexKey(),
                'exception' => $exception,
            ]);
        }
    }

    private function createProgress(QueueJob $job): ProgressAwareInterface
    {
        if ($this->progressStore === null) {
            return new NullProgressReporter($job->id());
        }

        return new ProgressReporter(
            store: $this->progressStore,
            jobId: $job->id(),
            attempt: $job->attempts() + 1,
            stepPercent: $this->progressStepPercent,
        );
    }

    /**
     * @param callable(mixed, QueueJob, ProgressAwareInterface): void $handler
     */
    private function invokeHandler(callable $handler, QueueJob $job, ProgressAwareInterface $progress): void
    {
        $arity = $this->resolveHandlerArity($handler);
        if ($arity >= 3) {
            $handler($job->payload(), $job, $progress);

            return;
        }

        if ($arity === 2) {
            $handler($job->payload(), $job);

            return;
        }

        if ($arity === 1) {
            $handler($job->payload());

            return;
        }

        $handler();
    }

    private function resolveHandlerArity(callable $handler): int
    {
        try {
            if (is_array($handler)) {
                $reflection = new ReflectionMethod($handler[0], (string) $handler[1]);

                return $reflection->isVariadic() ? 3 : $reflection->getNumberOfParameters();
            }

            if (is_string($handler) && str_contains($handler, '::')) {
                $reflection = new ReflectionMethod($handler);

                return $reflection->isVariadic() ? 3 : $reflection->getNumberOfParameters();
            }

            if (is_object($handler) && !$handler instanceof Closure) {
                $reflection = new ReflectionMethod($handler, '__invoke');

                return $reflection->isVariadic() ? 3 : $reflection->getNumberOfParameters();
            }

            $reflection = new ReflectionFunction($handler);

            return $reflection->isVariadic() ? 3 : $reflection->getNumberOfParameters();
        } catch (ReflectionException) {
            return 3;
        }
    }

    private function dispatchEvent(object $event): void
    {
        $this->events?->dispatch($event);
    }

    private function dispatchStatusChange(
        QueueJob $job,
        ProgressAwareInterface $progress,
        ?string $previousStatus,
        string $status,
        ?Throwable $exception = null,
    ): void {
        $this->dispatchEvent(new JobStatusChangedEvent(
            job: $job,
            progress: $progress,
            previousStatus: $previousStatus,
            status: $status,
            exception: $exception,
        ));
    }
}
