<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use PhpSoftBox\Queue\Worker;
use Throwable;

use function in_array;
use function max;
use function sleep;
use function str_contains;
use function strtolower;

final readonly class QueueListenHandler implements HandlerInterface
{
    public function __construct(
        private Worker $worker,
        private QueueJobHandlerInterface $handler,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $maxJobs      = (int) $runner->request()->option('max-jobs', 0);
        $sleepSeconds = (int) $runner->request()->option('sleep', 1);

        $processedTotal = 0;
        while (true) {
            $limit = $maxJobs > 0 ? max(0, $maxJobs - $processedTotal) : 0;

            try {
                $processed = $this->worker->run(
                    fn (mixed $payload, QueueJob $job) => $this->handler->handle($payload, $job),
                    $limit,
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
                break;
            }

            if ($processed === 0) {
                sleep(max(1, $sleepSeconds));
            }
        }

        return Response::SUCCESS;
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
