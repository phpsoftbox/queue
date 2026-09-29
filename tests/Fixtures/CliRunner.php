<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests\Fixtures;

use PhpSoftBox\CliApp\Io\IoInterface;
use PhpSoftBox\CliApp\Io\NullIo;
use PhpSoftBox\CliApp\Request\Request;
use PhpSoftBox\CliApp\Response;
use PhpSoftBox\CliApp\Runner\RunnerInterface;

final class CliRunner implements RunnerInterface
{
    private readonly IoInterface $io;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private readonly array $options = [],
    ) {
        $this->io = new NullIo();
    }

    public function run(string $command, array $argv): Response
    {
        return new Response(Response::SUCCESS);
    }

    public function runSubCommand(string $command, array $argv): Response
    {
        return new Response(Response::SUCCESS);
    }

    public function request(): Request
    {
        return new Request([], $this->options);
    }

    public function io(): IoInterface
    {
        return $this->io;
    }

    public function environment(): string
    {
        return 'test';
    }
}
