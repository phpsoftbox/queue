<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Worker::class)]
#[CoversMethod(Worker::class, 'run')]
final class WorkerResetStateTest extends TestCase
{
    /**
     * Проверим, что сброс состояния вызывается после каждой задачи — и успешной, и упавшей.
     *
     * @see Worker::run()
     */
    #[Test]
    public function resetsStateAfterEachJob(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('ok', null));
        $queue->push(new QueueJob('fail', null));
        $resets = 0;

        $worker = new Worker($queue, maxAttempts: 1, resetState: static function () use (&$resets): void {
            $resets++;
        });

        $worker->run(static function (mixed $payload, QueueJob $job): void {
            if ($job->id() === 'fail') {
                throw new RuntimeException('Job failed.');
            }
        });

        self::assertSame(2, $resets);
    }

    /**
     * Проверим, что ошибка сброса не останавливает воркер: следующая задача выполняется.
     *
     * @see Worker::run()
     */
    #[Test]
    public function continuesWhenResetFails(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('first', null));
        $queue->push(new QueueJob('second', null));
        $handled = [];

        $worker = new Worker($queue, resetState: static function (): never {
            throw new RuntimeException('Reset failed.');
        });

        $worker->run(static function (mixed $payload, QueueJob $job) use (&$handled): void {
            $handled[] = $job->id();
        });

        self::assertSame(['first', 'second'], $handled);
    }
}
