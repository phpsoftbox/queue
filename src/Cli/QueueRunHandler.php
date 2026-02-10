<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use PhpSoftBox\Queue\Worker;

final readonly class QueueRunHandler implements HandlerInterface
{
    public function __construct(
        private Worker $worker,
        private QueueJobHandlerInterface $handler,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $debug  = (bool) $runner->request()->option('debug', false);
        $logger = $debug ? new IoLogger($runner->io()) : null;

        $processed = $this->worker->run(
            fn (mixed $payload, QueueJob $job) => $this->handler->handle($payload, $job),
            0,
            $logger,
        );

        $runner->io()->writeln('Выполнено задач: ' . $processed);

        return Response::SUCCESS;
    }
}
