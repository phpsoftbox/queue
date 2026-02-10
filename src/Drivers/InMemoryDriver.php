<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexAwareInterface;
use PhpSoftBox\Queue\QueueMutexConflictException;
use SplQueue;

use function is_array;
use function is_int;
use function is_string;
use function time;
use function trim;

final class InMemoryDriver implements QueueInterface, QueueMutexAwareInterface
{
    /** @var SplQueue<QueueJob> */
    private SplQueue $queue;

    /**
     * @var array<string, array{owner:string, expires_at:int}>
     */
    private array $mutexes = [];

    public function __construct()
    {
        $this->queue = new SplQueue();
    }

    public function push(QueueJob $job): void
    {
        $this->purgeExpiredMutexes();

        $mutexKey = $this->normalizeMutexKey($job->mutexKey());
        if ($mutexKey !== null) {
            $existing = $this->mutexes[$mutexKey] ?? null;
            if (is_array($existing) && ($existing['owner'] ?? '') !== $job->id()) {
                throw new QueueMutexConflictException($mutexKey);
            }

            $ttl                      = $job->mutexTtlSeconds();
            $resolvedTtl              = is_int($ttl) && $ttl > 0 ? $ttl : 3600;
            $this->mutexes[$mutexKey] = [
                'owner'      => $job->id(),
                'expires_at' => time() + $resolvedTtl,
            ];
        }

        $this->queue->enqueue($job);
    }

    public function pop(): ?QueueJob
    {
        if ($this->queue->isEmpty()) {
            return null;
        }

        $now   = time();
        $count = $this->queue->count();

        for ($i = 0; $i < $count; $i++) {
            $job = $this->queue->dequeue();
            if (!$job instanceof QueueJob) {
                continue;
            }

            $availableAt = $job->availableAt();
            if ($availableAt !== null && $availableAt > $now) {
                $this->queue->enqueue($job);

                continue;
            }

            return $job;
        }

        return null;
    }

    public function size(): int
    {
        return $this->queue->count();
    }

    public function releaseMutex(QueueJob $job): void
    {
        $mutexKey = $this->normalizeMutexKey($job->mutexKey());
        if ($mutexKey === null) {
            return;
        }

        $existing = $this->mutexes[$mutexKey] ?? null;
        if (!is_array($existing) || ($existing['owner'] ?? '') !== $job->id()) {
            return;
        }

        unset($this->mutexes[$mutexKey]);
    }

    private function normalizeMutexKey(?string $mutexKey): ?string
    {
        if (!is_string($mutexKey)) {
            return null;
        }

        $mutexKey = trim($mutexKey);

        return $mutexKey !== '' ? $mutexKey : null;
    }

    private function purgeExpiredMutexes(): void
    {
        $now = time();
        foreach ($this->mutexes as $key => $state) {
            if (!is_array($state) || (int) ($state['expires_at'] ?? 0) > $now) {
                continue;
            }

            unset($this->mutexes[$key]);
        }
    }
}
