<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueueReservationAwareInterface extends QueueInterface
{
    /**
     * Резервирует задачу для обработки (visibility timeout) и атомарно увеличивает её `attempts` на 1:
     * возвращённая задача несёт номер текущей попытки, а резервирование, не завершённое `acknowledge()`/`release()`
     * (воркер упал), всё равно учитывается.
     */
    public function reserve(): ?QueueJob;

    /**
     * Подтверждает успешную/финальную обработку и удаляет задачу из очереди.
     */
    public function acknowledge(QueueJob $job): void;

    /**
     * Возвращает задачу обратно в очередь, сохраняя `attempts` переданной задачи.
     */
    public function release(QueueJob $job, int $delaySeconds = 0): void;
}
