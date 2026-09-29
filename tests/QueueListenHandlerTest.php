<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use DateTimeImmutable;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\Clock\Clock;
use PhpSoftBox\Queue\Cli\QueueListenHandler;
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Tests\Fixtures\CallbackJobHandler;
use PhpSoftBox\Queue\Tests\Fixtures\CliRunner;
use PhpSoftBox\Queue\Worker;
use PhpSoftBox\Queue\WorkerStopCondition;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function getmypid;
use function pcntl_signal_get_handler;
use function posix_kill;

use const SIG_DFL;
use const SIGTERM;

#[CoversClass(QueueListenHandler::class)]
#[CoversClass(WorkerStopCondition::class)]
#[CoversMethod(QueueListenHandler::class, 'run')]
final class QueueListenHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::reset();
    }

    /**
     * Проверим, что SIGTERM посреди задачи не обрывает её: задача доделывается и подтверждается, следующая
     * не берётся, команда завершается успешно и возвращает обработчик сигнала.
     *
     * @see QueueListenHandler::run()
     * @see WorkerStopCondition::listenForSignals()
     */
    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function sigtermFinishesCurrentJobAndExits(): void
    {
        $queue   = $this->queueWithJobs(2);
        $handled = [];
        $handler = new CallbackJobHandler(static function (mixed $payload) use (&$handled): void {
            posix_kill((int) getmypid(), SIGTERM);
            // Задача продолжается после сигнала.
            $handled[] = $payload;
        });

        $result = new QueueListenHandler(new Worker($queue), $handler)->run(new CliRunner());

        self::assertSame(Response::SUCCESS, $result);
        self::assertSame(['job-1'], $handled);
        self::assertSame(1, $queue->size());
        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM));
    }

    /**
     * Проверим, что при превышении --max-time команда выходит после текущей задачи.
     *
     * @see QueueListenHandler::run()
     */
    #[Test]
    public function maxTimeStopsAfterCurrentJob(): void
    {
        Clock::freeze(new DateTimeImmutable('2026-01-01 12:00:00'));

        $queue   = $this->queueWithJobs(3);
        $handled = [];
        $handler = new CallbackJobHandler(static function (mixed $payload) use (&$handled): void {
            Clock::travel(10);
            $handled[] = $payload;
        });

        $result = new QueueListenHandler(new Worker($queue), $handler)->run(new CliRunner(['max-time' => 5]));

        self::assertSame(Response::SUCCESS, $result);
        self::assertSame(['job-1'], $handled);
        self::assertSame(2, $queue->size());
    }

    /**
     * Проверим, что при превышении --memory (мегабайты) команда выходит, не беря следующую задачу.
     *
     * @see QueueListenHandler::run()
     */
    #[Test]
    public function memoryLimitStopsBeforeNextJob(): void
    {
        $queue   = $this->queueWithJobs(3);
        $handled = [];
        $handler = new CallbackJobHandler(static function (mixed $payload) use (&$handled): void {
            $handled[] = $payload;
        });

        // Лимит 1 МБ превышен уже при старте: проверка перед задачей не даёт взять ни одной.
        $result = new QueueListenHandler(new Worker($queue), $handler)->run(new CliRunner(['memory' => 1]));

        self::assertSame(Response::SUCCESS, $result);
        self::assertSame([], $handled);
        self::assertSame(3, $queue->size());
    }

    private function queueWithJobs(int $count): InMemoryDriver
    {
        $queue = new InMemoryDriver();
        for ($i = 1; $i <= $count; $i++) {
            $queue->push(QueueJob::fromPayload('job-' . $i, 'job-' . $i));
        }

        return $queue;
    }
}
