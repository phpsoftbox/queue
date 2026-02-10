<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Io\IoInterface;
use Psr\Log\AbstractLogger;

use function json_encode;
use function sprintf;
use function strtoupper;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class IoLogger extends AbstractLogger
{
    public function __construct(
        private readonly IoInterface $io,
    ) {
    }

    public function log($level, $message, array $context = []): void
    {
        $suffix = '';
        if ($context !== []) {
            $suffix = ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $style = match ($level) {
            'error', 'critical', 'alert', 'emergency' => 'error',
            'warning'                                 => 'comment',
            default                                   => 'info',
        };

        $this->io->writeln(sprintf('[%s] %s%s', strtoupper((string) $level), (string) $message, $suffix), $style);
    }
}
