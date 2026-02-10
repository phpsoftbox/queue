<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\HandlerInterface;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;
use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;

use function is_string;

final class QueuePushHandler implements HandlerInterface
{
    public function __construct(
        private readonly QueueInterface $queue,
    ) {
    }

    public function run(RunnerInterface $runner): int|Response
    {
        $payload = $runner->request()->param('payload');
        if (!is_string($payload)) {
            $runner->io()->writeln('Payload должен быть строкой.', 'error');

            return Response::FAILURE;
        }

        $id  = $runner->request()->option('id');
        $job = QueueJob::fromPayload($payload, is_string($id) && $id !== '' ? $id : null);

        $this->queue->push($job);

        $runner->io()->writeln('Задание добавлено: ' . $job->id(), 'success');

        return Response::SUCCESS;
    }
}
