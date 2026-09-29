<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests\Fixtures;

use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use RuntimeException;

/**
 * Хранилище прогресса, недоступное при старте задачи.
 */
final class FailingStartProgressStore implements ProgressStoreInterface
{
    public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
    {
        throw new RuntimeException('Progress store is unavailable.');
    }

    public function setTotal(string $jobId, ?int $total): void
    {
    }

    public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
    {
    }

    public function setStatus(string $jobId, string $status, ?string $error = null): void
    {
    }

    public function setMeta(string $jobId, array $meta): void
    {
    }

    public function requestCancellation(string $jobId): bool
    {
        return false;
    }

    public function isCancellationRequested(string $jobId): bool
    {
        return false;
    }

    public function snapshot(string $jobId): ?QueueProgressSnapshot
    {
        return null;
    }
}
