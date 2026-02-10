<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueReservationAwareInterface extends QueueInterface
{
    /**
     * Резервирует задачу для обработки (visibility timeout).
     */
    public function reserve(): ?QueueJob;

    /**
     * Подтверждает успешную/финальную обработку и удаляет задачу из очереди.
     */
    public function acknowledge(QueueJob $job): void;

    /**
     * Возвращает задачу обратно в очередь.
     */
    public function release(QueueJob $job, int $delaySeconds = 0): void;
}
