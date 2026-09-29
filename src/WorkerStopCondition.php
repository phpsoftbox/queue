<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use PhpSoftBox\Clock\Clock;

use function constant;
use function defined;
use function function_exists;
use function memory_get_usage;
use function pcntl_async_signals;
use function pcntl_signal;
use function pcntl_signal_get_handler;
use function sprintf;

/**
 * Условие остановки долгоживущего воркера (`queue:listen`): сигнал SIGTERM/SIGINT, лимит времени работы и памяти.
 * Проверяется между задачами (`Worker::run(..., shouldStop: $condition->shouldStop(...))`), поэтому текущая
 * задача всегда доделывается.
 */
final class WorkerStopCondition
{
    private ?string $reason = null;
    private readonly int $startedAt;

    /**
     * @var array<int, mixed> обработчики сигналов до `listenForSignals()`
     */
    private array $previousHandlers     = [];
    private ?bool $previousAsyncSignals = null;

    /**
     * @param int $maxTimeSeconds остановиться, когда процесс работает дольше (`0` — без лимита)
     * @param int $memoryLimitMegabytes остановиться, когда процесс занял больше памяти (`0` — без лимита)
     */
    public function __construct(
        private readonly int $maxTimeSeconds = 0,
        private readonly int $memoryLimitMegabytes = 0,
    ) {
        $this->startedAt = Clock::now()->getTimestamp();
    }

    /**
     * Перехватывает SIGTERM и SIGINT: сигнал только запрашивает остановку. Без расширения pcntl возвращает `false`
     * и сигналы завершают процесс как обычно.
     */
    public function listenForSignals(): bool
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return false;
        }

        $this->previousAsyncSignals = pcntl_async_signals(true);

        foreach (['SIGTERM', 'SIGINT'] as $name) {
            if (!defined($name)) {
                continue;
            }

            $signal = (int) constant($name);

            $this->previousHandlers[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, function () use ($name): void {
                $this->requestStop('signal ' . $name);
            });
        }

        return true;
    }

    /**
     * Возвращает обработчики сигналов, действовавшие до `listenForSignals()`.
     */
    public function restoreSignals(): void
    {
        foreach ($this->previousHandlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }

        if ($this->previousAsyncSignals !== null) {
            pcntl_async_signals($this->previousAsyncSignals);
        }

        $this->previousHandlers     = [];
        $this->previousAsyncSignals = null;
    }

    public function requestStop(string $reason): void
    {
        $this->reason ??= $reason;
    }

    public function shouldStop(): bool
    {
        if ($this->reason !== null) {
            return true;
        }

        $elapsed = Clock::now()->getTimestamp() - $this->startedAt;
        if ($this->maxTimeSeconds > 0 && $elapsed >= $this->maxTimeSeconds) {
            $this->requestStop(sprintf('max time %d s reached', $this->maxTimeSeconds));

            return true;
        }

        $limitBytes = $this->memoryLimitMegabytes * 1024 * 1024;
        if ($limitBytes > 0 && memory_get_usage(true) >= $limitBytes) {
            $this->requestStop(sprintf('memory limit %d MB reached', $this->memoryLimitMegabytes));

            return true;
        }

        return false;
    }

    /**
     * Причина остановки или `null`, если остановка не запрошена.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
