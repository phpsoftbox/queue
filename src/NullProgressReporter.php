<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

final class NullProgressReporter implements ProgressAwareInterface
{
    public function __construct(
        private readonly string $jobId,
    ) {
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function setTotal(?int $total): void
    {
    }

    public function increment(int $amount = 1): void
    {
    }

    public function setProcessed(int $processed): void
    {
    }

    public function setStatus(string $status, ?string $error = null): void
    {
    }

    public function setMeta(array $meta): void
    {
    }

    public function isCancellationRequested(): bool
    {
        return false;
    }
}
