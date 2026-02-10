<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use JsonException;
use PhpSoftBox\Clock\Clock;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use PhpSoftBox\Queue\QueueProgressStatus;

use function in_array;
use function json_decode;
use function json_encode;
use function max;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/** State belongs to this instance only; it is not shared between processes. */
final class InMemoryProgressStore implements ProgressStoreInterface
{
    /** @var array<string, array<string, mixed>> */
    private array $jobs = [];

    public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return;
        }
        $now                = $this->now();
        $previous           = $this->jobs[$jobId] ?? null;
        $this->jobs[$jobId] = [
            'status'            => QueueProgressStatus::QUEUED,
            'attempt'           => max(1, $attempt),
            'total'             => null,
            'processed'         => 0,
            'percent'           => 0,
            'error'             => null,
            'meta'              => $previous['meta'] ?? [],
            'cancelRequestedAt' => $previous['cancelRequestedAt'] ?? null,
            'startedAt'         => $now,
            'finishedAt'        => null,
            'updatedAt'         => $now,
        ];
    }

    public function setTotal(string $jobId, ?int $total): void
    {
        $this->update($jobId, ['total' => $total !== null && $total >= 0 ? $total : null]);
    }

    public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
    {
        $this->update($jobId, ['processed' => max(0, $processed), 'percent' => max(0, $percent ?? 0)]);
    }

    public function setStatus(string $jobId, string $status, ?string $error = null): void
    {
        $jobId  = trim($jobId);
        $status = trim($status);
        if ($status === '' || !isset($this->jobs[$jobId])) {
            return;
        }
        if ($status === QueueProgressStatus::COMPLETED && in_array(
            $this->jobs[$jobId]['status'],
            [QueueProgressStatus::FAILED, QueueProgressStatus::CANCELLED],
            true,
        )) {
            return;
        }
        $finished = in_array($status, [QueueProgressStatus::COMPLETED, QueueProgressStatus::FAILED, QueueProgressStatus::CANCELLED], true);
        $this->update($jobId, [
            'status'     => $status,
            'error'      => $error === null || trim($error) === '' ? null : trim($error),
            'finishedAt' => $finished ? $this->now() : null,
        ]);
    }

    public function setMeta(string $jobId, array $meta): void
    {
        $jobId = trim($jobId);
        if ($jobId === '' || $meta === []) {
            return;
        }
        // JSON round-trip matches database snapshots, including nested object detachment.
        try {
            $json = json_encode([ ...($this->jobs[$jobId]['meta'] ?? []), ...$meta], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to encode queue progress meta.', 0, $exception);
        }
        $this->update($jobId, ['meta' => $data]);
    }

    public function requestCancellation(string $jobId): bool
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return false;
        }
        if (!isset($this->jobs[$jobId])) {
            $this->start($jobId);
            $this->jobs[$jobId]['startedAt'] = null;
        }
        $this->update($jobId, ['cancelRequestedAt' => $this->now()]);

        return true;
    }

    public function isCancellationRequested(string $jobId): bool
    {
        return ($this->jobs[trim($jobId)]['cancelRequestedAt'] ?? null) !== null;
    }

    public function snapshot(string $jobId): ?QueueProgressSnapshot
    {
        $state = $this->jobs[trim($jobId)] ?? null;

        return $state === null ? null : new QueueProgressSnapshot(...$state);
    }

    public function forget(string $jobId): void
    {
        unset($this->jobs[trim($jobId)]);
    }

    public function clear(): void
    {
        $this->jobs = [];
    }

    /** @param array<string, mixed> $changes */
    private function update(string $jobId, array $changes): void
    {
        $jobId = trim($jobId);
        if (!isset($this->jobs[$jobId])) {
            return;
        }
        $this->jobs[$jobId] = [...$this->jobs[$jobId], ...$changes, 'updatedAt' => $this->now()];
    }

    private function now(): string
    {
        return Clock::now()->format('Y-m-d H:i:s');
    }
}
