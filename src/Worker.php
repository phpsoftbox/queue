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
        /**
         * Сброс состояния после каждой задачи (например, `ServicesResetter::reset()` из phpsoftbox/container):
         * задачи одного воркера не должны видеть кеши, identity map и контекст предыдущих.
         *
         * @var null|Closure():void
         */
        private ?Closure $resetState = null,
    ) {
    }

    /**
     * Обрабатывает задачи, пока очередь не опустеет, не достигнут `$maxJobs` (`0` — без лимита) или `$shouldStop`
     * не вернёт `true`. `$shouldStop` проверяется перед каждой задачей: текущая задача всегда доделывается.
     *
     * @param callable(mixed, QueueJob, ProgressAwareInterface): void $handler
     * @param null|Closure():bool $shouldStop
     */
    public function run(
        callable $handler,
        int $maxJobs = 0,
        ?LoggerInterface $logger = null,
        ?Closure $shouldStop = null,
    ): int {
        $logger ??= $this->logger;
        $processed = 0;

        while ($maxJobs === 0 || $processed < $maxJobs) {
            if ($shouldStop !== null && $shouldStop()) {
                break;
            }

            $job = $this->pullJob();
            if ($job === null) {
                break;
            }

            $processed++;
            $progress = $this->prepareJob($job, $logger, fn (): ProgressAwareInterface => $this->createProgress($job));
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
                $this->resetState($logger);
                continue;
            }

            if ($job->attempts() > $this->maxAttempts()) {
                $this->failLostJob($job, $progress, $logger);
                continue;
            }

            $status = QueueProgressStatus::PROCESSING;
            $this->prepareJob($job, $logger, function () use ($job, $progress, $status, $logger): void {
                $progress->setStatus($status);
                $this->dispatchStatusChange($job, $progress, QueueProgressStatus::QUEUED, $status);
                $logger?->info('Queue job started', [
                    'job_id'   => $job->id(),
                    'attempt'  => $job->attempts(),
                    'priority' => $job->priority(),
                ]);
                $this->dispatchEvent(new JobBeforeEvent($job, $progress));
            });

            $lastException = null;
            try {
                try {
                    $this->invokeHandler($handler, $job, $progress);
                } catch (Throwable $exception) {
                    $lastException = $exception;
                }

                // Ошибки учёта после успешного обработчика (прогресс, слушатели событий) не делают задачу
                // упавшей: иначе она ушла бы на повтор и выполнилась ещё раз.
                $status = $lastException === null
                    ? $this->completeJob($job, $progress, $logger)
                    : $this->failJob($job, $progress, $lastException, $logger);
            } finally {
                $this->dispatchEvent(new JobAfterEvent($job, $progress, $status, $lastException));
                $this->resetState($logger);
            }
        }

        return $processed;
    }

    /**
     * Подготовка до запуска обработчика (прогресс, статус `processing`, события). Если она упала, задача
     * возвращается в очередь сразу, а не остаётся зарезервированной до visibility timeout; ошибка пробрасывается.
     *
     * @template T
     * @param Closure():T $step
     * @return T
     */
    private function prepareJob(QueueJob $job, ?LoggerInterface $logger, Closure $step): mixed
    {
        try {
            return $step();
        } catch (Throwable $exception) {
            try {
                $this->retryJob($job, 0);
            } catch (Throwable $releaseException) {
                $logger?->error('Queue job return after preparation failure failed', [
                    'job_id'    => $job->id(),
                    'exception' => $releaseException::class,
                    'message'   => $releaseException->getMessage(),
                ]);
            }

            $this->resetState($logger);

            throw $exception;
        }
    }

    /**
     * Задача зарезервирована больше `maxAttempts` раз, а обработчик ни разу не вернул результат: воркер падал
     * посреди неё (OOM, fatal, kill) или она выполнялась дольше visibility timeout. Такая задача не выполняется
     * снова, а сразу уходит в failed.
     */
    private function failLostJob(QueueJob $job, ProgressAwareInterface $progress, ?LoggerInterface $logger): void
    {
        $status    = QueueProgressStatus::FAILED;
        $exception = QueueJobLostException::forJob($job, $this->maxAttempts());

        $logger?->error('Queue job lost', [
            'job_id'       => $job->id(),
            'attempt'      => $job->attempts(),
            'max_attempts' => $this->maxAttempts(),
            'message'      => $exception->getMessage(),
        ]);

        try {
            try {
                $progress->setStatus($status, $exception->getMessage());
                $this->dispatchStatusChange($job, $progress, QueueProgressStatus::QUEUED, $status, $exception);
                $this->failedStore?->store($job, $exception);
                if ($this->onFailure !== null) {
                    ($this->onFailure)($job, $exception);
                }
            } finally {
                try {
                    $this->acknowledgeReservedJob($job);
                } finally {
                    $this->releaseMutexSafely($job, $logger);
                }
            }
        } finally {
            $this->dispatchEvent(new JobAfterEvent($job, $progress, $status, $exception));
            $this->resetState($logger);
        }
    }

    private function maxAttempts(): int
    {
        return max(1, $this->maxAttempts);
    }

    private function completeJob(QueueJob $job, ProgressAwareInterface $progress, ?LoggerInterface $logger): string
    {
        $status = QueueProgressStatus::COMPLETED;
        try {
            $progress->setStatus($status);
            $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status);
        } finally {
            try {
                $this->acknowledgeReservedJob($job);
            } finally {
                $this->releaseMutexSafely($job, $logger);
            }
        }

        $logger?->info('Queue job completed', [
            'job_id'  => $job->id(),
            'attempt' => $job->attempts(),
        ]);

        return $status;
    }

    private function failJob(
        QueueJob $job,
        ProgressAwareInterface $progress,
        Throwable $exception,
        ?LoggerInterface $logger,
    ): string {
        $logger?->error('Queue job failed', [
            'job_id'    => $job->id(),
            'attempt'   => $job->attempts(),
            'exception' => $exception::class,
            'message'   => $exception->getMessage(),
        ]);

        if ($job->attempts() < $this->maxAttempts()) {
            $status       = QueueProgressStatus::RETRYING;
            $delaySeconds = $this->resolveRetryDelay($job, $exception);
            try {
                $progress->setStatus($status, $exception->getMessage());
                $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status, $exception);
            } finally {
                $this->retryJob($job, $delaySeconds);
            }

            $logger?->warning('Queue job requeued', [
                'job_id'        => $job->id(),
                'attempt'       => $job->attempts(),
                'delay_seconds' => $delaySeconds,
            ]);

            return $status;
        }

        $status = QueueProgressStatus::FAILED;
        try {
            $progress->setStatus($status, $exception->getMessage());
            $this->dispatchStatusChange($job, $progress, QueueProgressStatus::PROCESSING, $status, $exception);
            $this->failedStore?->store($job, $exception);
            if ($this->onFailure !== null) {
                ($this->onFailure)($job, $exception);
            }
        } finally {
            try {
                $this->acknowledgeReservedJob($job);
            } finally {
                $this->releaseMutexSafely($job, $logger);
            }
        }

        return $status;
    }

    /**
     * Ошибка сброса не останавливает воркер: она пишется в лог, следующая задача выполняется.
     */
    private function resetState(?LoggerInterface $logger): void
    {
        if ($this->resetState === null) {
            return;
        }

        try {
            ($this->resetState)();
        } catch (Throwable $exception) {
            $logger?->error('Queue worker state reset failed', [
                'exception' => $exception::class,
                'message'   => $exception->getMessage(),
            ]);
        }
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
            attempt: $job->attempts(),
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
