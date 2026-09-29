<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use DateTimeImmutable;
use PhpSoftBox\Clock\Clock;
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

#[CoversClass(WorkerStopCondition::class)]
#[CoversMethod(WorkerStopCondition::class, 'shouldStop')]
#[CoversMethod(WorkerStopCondition::class, 'listenForSignals')]
#[CoversMethod(WorkerStopCondition::class, 'restoreSignals')]
final class WorkerStopConditionTest extends TestCase
{
    protected function tearDown(): void
    {
        Clock::reset();
    }

    /**
     * Проверим, что без лимитов и сигналов остановка не запрашивается.
     *
     * @see WorkerStopCondition::shouldStop()
     */
    #[Test]
    public function noLimitsDoNotStop(): void
    {
        $condition = new WorkerStopCondition();

        self::assertFalse($condition->shouldStop());
        self::assertNull($condition->reason());
    }

    /**
     * Проверим, что остановка запрашивается, когда процесс работает дольше maxTimeSeconds.
     *
     * @see WorkerStopCondition::shouldStop()
     */
    #[Test]
    public function stopsWhenMaxTimeReached(): void
    {
        Clock::freeze(new DateTimeImmutable('2026-01-01 12:00:00'));
        $condition = new WorkerStopCondition(maxTimeSeconds: 60);

        Clock::travel(59);
        self::assertFalse($condition->shouldStop());

        Clock::travel(1);
        self::assertTrue($condition->shouldStop());
        self::assertSame('max time 60 s reached', $condition->reason());
    }

    /**
     * Проверим, что остановка запрашивается, когда процесс занял больше memoryLimitMegabytes.
     *
     * @see WorkerStopCondition::shouldStop()
     */
    #[Test]
    public function stopsWhenMemoryLimitReached(): void
    {
        // PHPUnit с автозагрузкой занимает заведомо больше 1 МБ.
        $condition = new WorkerStopCondition(memoryLimitMegabytes: 1);

        self::assertTrue($condition->shouldStop());
        self::assertSame('memory limit 1 MB reached', $condition->reason());
    }

    /**
     * Проверим, что SIGTERM не завершает процесс, а запрашивает остановку, и что restoreSignals() возвращает
     * прежний обработчик.
     *
     * @see WorkerStopCondition::listenForSignals()
     * @see WorkerStopCondition::restoreSignals()
     */
    #[Test]
    #[RequiresPhpExtension('pcntl')]
    #[RequiresPhpExtension('posix')]
    public function sigtermRequestsStop(): void
    {
        $condition = new WorkerStopCondition();

        self::assertTrue($condition->listenForSignals());

        try {
            posix_kill((int) getmypid(), SIGTERM);

            self::assertTrue($condition->shouldStop());
            self::assertSame('signal SIGTERM', $condition->reason());
        } finally {
            $condition->restoreSignals();
        }

        self::assertSame(SIG_DFL, pcntl_signal_get_handler(SIGTERM));
    }
}
