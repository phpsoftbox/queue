<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface ProgressAwareInterface
{
    /**
     * Идентификатор job, с которым связан репортер прогресса.
     */
    public function jobId(): string;

    /**
     * Общее количество единиц обработки (null => total неизвестен).
     */
    public function setTotal(?int $total): void;

    /**
     * Увеличивает количество обработанных единиц.
     */
    public function increment(int $amount = 1): void;

    /**
     * Устанавливает абсолютное количество обработанных единиц.
     */
    public function setProcessed(int $processed): void;

    /**
     * Обновляет статус выполнения задачи.
     */
    public function setStatus(string $status, ?string $error = null): void;

    /**
     * @param array<string, mixed> $meta
     */
    public function setMeta(array $meta): void;

    /**
     * Возвращает true, если для задачи запрошена отмена.
     */
    public function isCancellationRequested(): bool;
}
