<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use PhpSoftBox\Queue\Worker;
use PhpSoftBox\Queue\WorkerStopCondition;
use Throwable;

use function in_array;
use function max;
use function sleep;
use function sprintf;
use function str_contains;
use function strtolower;

final readonly class QueueListenHandler implements HandlerInterface
{
    public function __construct(
        private Worker $worker,
        private QueueJobHandlerInterface $handler,
    ) {
    }

    /**
     * Обрабатывает очередь до сигнала SIGTERM/SIGINT (нужно расширение pcntl) или лимита `--max-jobs`,
     * `--max-time` (секунды), `--memory` (мегабайты). Лимиты и сигналы проверяются между задачами: текущая задача
     * доделывается, затем процесс выходит с кодом 0 — супервизор (systemd, supervisord, Docker) запускает новый.
     */
    public function run(RunnerInterface $runner): int|Response
    {
        $maxJobs      = max(0, (int) $runner->request()->option('max-jobs', 0));
        $sleepSeconds = (int) $runner->request()->option('sleep', 1);
        $stop         = new WorkerStopCondition(
            maxTimeSeconds: max(0, (int) $runner->request()->option('max-time', 0)),
            memoryLimitMegabytes: max(0, (int) $runner->request()->option('memory', 0)),
        );

        $stop->listenForSignals();

        try {
            $this->listen($runner, $stop, $maxJobs, $sleepSeconds);
        } finally {
            $stop->restoreSignals();
        }

        $runner->io()->writeln('Queue listener stopped: ' . ($stop->reason() ?? 'unknown reason'));

        return Response::SUCCESS;
    }

    private function listen(RunnerInterface $runner, WorkerStopCondition $stop, int $maxJobs, int $sleepSeconds): void
    {
        $processedTotal = 0;
        while (!$stop->shouldStop()) {
            $limit = $maxJobs > 0 ? $maxJobs - $processedTotal : 0;

            try {
                $processed = $this->worker->run(
                    fn (mixed $payload, QueueJob $job) => $this->handler->handle($payload, $job),
                    $limit,
                    shouldStop: $stop->shouldStop(...),
                );
            } catch (Throwable $exception) {
                if (!$this->isRecoverableInfrastructureError($exception)) {
                    throw $exception;
                }

                $runner->io()->writeln(
                    'Queue iteration failed: ' . $exception->getMessage(),
                    'error',
                );
                sleep(max(1, $sleepSeconds));

                continue;
            }

            $processedTotal += $processed;

            if ($maxJobs > 0 && $processedTotal >= $maxJobs) {
                $stop->requestStop(sprintf('max jobs %d reached', $maxJobs));

                break;
            }

            if ($processed === 0) {
                // Сигнал прерывает sleep(): выход не ждёт окончания паузы.
                sleep(max(1, $sleepSeconds));
            }
        }
    }

    private function isRecoverableInfrastructureError(Throwable $exception): bool
    {
        $current = $exception;
        while ($current instanceof Throwable) {
            $code = (string) $current->getCode();
            if (in_array($code, ['2006', '2013'], true)) {
                return true;
            }

            $message = strtolower($current->getMessage());
            if (
                str_contains($message, 'server has gone away')
                || str_contains($message, 'lost connection')
                || str_contains($message, 'server closed the connection unexpectedly')
                || str_contains($message, 'no connection to the server')
                || str_contains($message, 'connection refused')
                || str_contains($message, 'name or service not known')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}
