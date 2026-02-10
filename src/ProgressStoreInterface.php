<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface ProgressStoreInterface
{
    /**
     * Инициализирует запись прогресса для задачи.
     */
    public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void;

    /**
     * Устанавливает total.
     */
    public function setTotal(string $jobId, ?int $total): void;

    /**
     * Устанавливает processed и опционально percent.
     */
    public function setProcessed(string $jobId, int $processed, ?int $percent = null): void;

    /**
     * Обновляет статус и сообщение об ошибке.
     */
    public function setStatus(string $jobId, string $status, ?string $error = null): void;

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(string $jobId, array $meta): void;

    /**
     * Запрашивает отмену задачи.
     */
    public function requestCancellation(string $jobId): bool;

    /**
     * Возвращает true, если ранее была запрошена отмена.
     */
    public function isCancellationRequested(string $jobId): bool;

    /**
     * Возвращает snapshot прогресса по job.
     */
    public function snapshot(string $jobId): ?QueueProgressSnapshot;
}
