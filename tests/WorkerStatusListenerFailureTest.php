<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\Event\JobStatusChangedEvent;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;

#[CoversClass(Worker::class)]
#[CoversMethod(Worker::class, 'run')]
final class WorkerStatusListenerFailureTest extends TestCase
{
    /**
     * Проверим, что ошибка слушателя статуса после успешного обработчика не отправляет задачу на повтор:
     * обработчик выполняется один раз, задача снимается с очереди, ошибка пробрасывается наружу.
     *
     * @see Worker::run()
     */
    #[Test]
    public function completedJobIsNotRetriedWhenStatusListenerFails(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job-1', null));

        // Слушатель падает на статусе completed.
        $worker = new Worker($queue, maxAttempts: 3, events: $this->failingDispatcher('completed'));

        $handled = 0;
        try {
            $worker->run(static function () use (&$handled): void {
                $handled++;
            });
            self::fail('Ошибка слушателя должна быть проброшена.');
        } catch (RuntimeException $exception) {
            self::assertSame('Listener failed on completed.', $exception->getMessage());
        }

        self::assertSame(1, $handled);
        self::assertSame(0, $queue->size());
    }

    /**
     * Проверим, что ошибка слушателя статуса retrying не теряет задачу: она всё равно возвращается в очередь.
     *
     * @see Worker::run()
     */
    #[Test]
    public function failedJobIsRequeuedWhenStatusListenerFails(): void
    {
        $queue = new InMemoryDriver();

        $queue->push(new QueueJob('job-1', null));

        // Слушатель падает на статусе retrying.
        $worker = new Worker($queue, maxAttempts: 3, events: $this->failingDispatcher('retrying'));

        try {
            $worker->run(static function (): void {
                throw new RuntimeException('Job failed.');
            }, maxJobs: 1);
            self::fail('Ошибка слушателя должна быть проброшена.');
        } catch (RuntimeException $exception) {
            self::assertSame('Listener failed on retrying.', $exception->getMessage());
        }

        $requeued = $queue->pop();
        self::assertInstanceOf(QueueJob::class, $requeued);
        self::assertSame('job-1', $requeued->id());
        // Повторная выдача — вторая попытка.
        self::assertSame(2, $requeued->attempts());
    }

    private function failingDispatcher(string $failOnStatus): EventDispatcherInterface
    {
        return new readonly class ($failOnStatus) implements EventDispatcherInterface {
            public function __construct(
                private string $failOnStatus,
            ) {
            }

            public function dispatch(object $event): object
            {
                if ($event instanceof JobStatusChangedEvent && $event->status === $this->failOnStatus) {
                    throw new RuntimeException('Listener failed on ' . $this->failOnStatus . '.');
                }

                return $event;
            }
        };
    }
}
