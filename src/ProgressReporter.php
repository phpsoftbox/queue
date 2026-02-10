<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use function intdiv;
use function max;
use function min;

final class ProgressReporter implements ProgressAwareInterface
{
    private ?int $total             = null;
    private int $processed          = 0;
    private int $lastFlushedPercent = -1;
    private int $stepPercent        = 1;

    public function __construct(
        private readonly ProgressStoreInterface $store,
        private readonly string $jobId,
        int $attempt = 1,
        int $stepPercent = 1,
    ) {
        $resolvedAttempt     = $attempt > 0 ? $attempt : 1;
        $resolvedStepPercent = min(100, max(1, $stepPercent));
        $this->stepPercent   = $resolvedStepPercent;

        $this->store->start($this->jobId, $resolvedAttempt, $resolvedStepPercent);
    }

    public function jobId(): string
    {
        return $this->jobId;
    }

    public function setTotal(?int $total): void
    {
        $this->total = $total !== null && $total >= 0 ? $total : null;

        $percent = $this->resolvePercent();
        if ($percent !== null) {
            $this->lastFlushedPercent = $percent;
        }

        $this->store->setTotal($this->jobId, $this->total);
        $this->store->setProcessed($this->jobId, $this->processed, $percent);
    }

    public function increment(int $amount = 1): void
    {
        if ($amount <= 0) {
            return;
        }

        $this->setProcessed($this->processed + $amount);
    }

    public function setProcessed(int $processed): void
    {
        $resolved        = max($processed, 0);
        $this->processed = $resolved;

        $percent = $this->resolvePercent();
        if (!$this->shouldFlush($percent)) {
            return;
        }

        if ($percent !== null) {
            $this->lastFlushedPercent = $percent;
        }

        $this->store->setProcessed($this->jobId, $this->processed, $percent);
    }

    public function setStatus(string $status, ?string $error = null): void
    {
        $this->store->setStatus($this->jobId, $status, $error);
    }

    public function setMeta(array $meta): void
    {
        $this->store->setMeta($this->jobId, $meta);
    }

    public function isCancellationRequested(): bool
    {
        return $this->store->isCancellationRequested($this->jobId);
    }

    private function resolvePercent(): ?int
    {
        if ($this->total === null || $this->total <= 0) {
            return null;
        }

        $processed = $this->processed;
        if ($processed > $this->total) {
            $processed = $this->total;
        }

        return intdiv($processed * 100, $this->total);
    }

    private function shouldFlush(?int $percent): bool
    {
        if ($percent === null) {
            return true;
        }

        if ($this->lastFlushedPercent < 0) {
            return true;
        }

        if ($percent >= 100) {
            return true;
        }

        return $percent >= $this->lastFlushedPercent + $this->stepPercent;
    }
}
