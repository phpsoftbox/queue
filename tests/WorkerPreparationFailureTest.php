<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Tests\Fixtures\FailingStartProgressStore;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(Worker::class)]
#[CoversMethod(Worker::class, 'run')]
final class WorkerPreparationFailureTest extends TestCase
{
    /**
     * Проверим, что ошибка ProgressStore::start() пробрасывается, а задача сразу возвращается в очередь, а не
     * остаётся зарезервированной до visibility timeout.
     *
     * @see Worker::run()
     * @see FailingStartProgressStore::start()
     */
    #[Test]
    public function progressStartFailureReturnsJobToQueue(): void
    {
        $database = new QueueDatabase();

        try {
            $queue = $database->driver();
            $queue->push(QueueJob::fromPayload(['task' => 'x'], 'job-1'));

            $handled = false;
            $worker  = new Worker($queue, progressStore: new FailingStartProgressStore());

            try {
                $worker->run(static function () use (&$handled): void {
                    $handled = true;
                });
                self::fail('Ошибка хранилища прогресса должна быть проброшена.');
            } catch (RuntimeException $exception) {
                self::assertSame('Progress store is unavailable.', $exception->getMessage());
            }

            self::assertFalse($handled);

            // Задача доступна сразу, без ожидания visibility timeout.
            $again = $queue->reserve();
            self::assertNotNull($again);
            self::assertSame('job-1', $again->id());
        } finally {
            $database->close();
        }
    }
}
