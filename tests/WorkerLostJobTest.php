<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobLostException;
use PhpSoftBox\Queue\Tests\Fixtures\RecordingFailedJobStore;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Задача, роняющая воркер (OOM, fatal, kill): резервирования без результата учитываются как попытки.
 * Работает на SQLite; на MariaDB/MySQL/PostgreSQL — с переменной окружения QUEUE_TEST_DSN.
 */
#[CoversClass(Worker::class)]
#[CoversClass(QueueJobLostException::class)]
#[CoversMethod(Worker::class, 'run')]
#[CoversMethod(DatabaseDriver::class, 'reserve')]
final class WorkerLostJobTest extends TestCase
{
    /**
     * Проверим, что задача, на которой воркер упал maxAttempts раз, не выполняется снова, а уходит в failed
     * с QueueJobLostException, снимается с очереди и освобождает mutex.
     *
     * @see Worker::run()
     * @see QueueJobLostException::forJob()
     */
    #[Test]
    public function jobLostMaxAttemptsTimesGoesToFailedWithoutRunning(): void
    {
        $database = new QueueDatabase();

        try {
            $queue = $database->driver();
            $queue->push(QueueJob::fromPayload(['task' => 'oom'], 'job-1', mutexKey: 'import'));

            // Два воркера по очереди зарезервировали задачу и умерли.
            $this->crashWorker($queue, $database);
            $this->crashWorker($queue, $database);

            $store  = new RecordingFailedJobStore();
            $failed = [];
            $worker = new Worker(
                $queue,
                maxAttempts: 2,
                onFailure: static function (QueueJob $job) use (&$failed): void {
                    $failed[] = $job->id();
                },
                failedStore: $store,
            );

            $handled   = 0;
            $processed = $worker->run(static function () use (&$handled): void {
                $handled++;
            });

            self::assertSame(1, $processed);
            self::assertSame(0, $handled, 'Задача, исчерпавшая попытки падениями воркера, не должна выполняться.');
            self::assertSame(['job-1'], $failed);
            self::assertCount(1, $store->stored);
            self::assertInstanceOf(QueueJobLostException::class, $store->stored[0]['exception']);
            self::assertSame(3, $store->stored[0]['job']->attempts());
            self::assertSame(0, $queue->size());

            // Mutex освобождён: задачу с тем же ключом можно поставить снова.
            $queue->push(QueueJob::fromPayload(['task' => 'next'], 'job-2', mutexKey: 'import'));
            self::assertSame(1, $queue->size());
        } finally {
            $database->close();
        }
    }

    /**
     * Проверим, что после одного падения воркера (попыток осталось) задача выполняется как вторая попытка.
     *
     * @see Worker::run()
     */
    #[Test]
    public function jobLostBelowMaxAttemptsRunsAsNextAttempt(): void
    {
        $database = new QueueDatabase();

        try {
            $queue = $database->driver();
            $queue->push(QueueJob::fromPayload(['task' => 'x'], 'job-1'));

            $this->crashWorker($queue, $database);

            $attempts = [];
            new Worker($queue, maxAttempts: 2)->run(static function (mixed $payload, QueueJob $job) use (&$attempts): void {
                $attempts[] = $job->attempts();
            });

            self::assertSame([2], $attempts);
            self::assertSame(0, $queue->size());
        } finally {
            $database->close();
        }
    }

    /**
     * Воркер зарезервировал задачу и умер: подтверждения нет, visibility timeout истёк.
     */
    private function crashWorker(DatabaseDriver $queue, QueueDatabase $database): void
    {
        self::assertNotNull($queue->reserve());
        $database->expireReservations();
    }
}
