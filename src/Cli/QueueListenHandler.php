<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use PhpSoftBox\Queue\Worker;

use function max;
use function sleep;

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

            $processed = $this->worker->run(
                fn (mixed $payload, QueueJob $job) => $this->handler->handle($payload, $job),
                $limit,
            );

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
}
