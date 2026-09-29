<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\QueueJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Работает на SQLite; на MariaDB/MySQL/PostgreSQL — с переменной окружения QUEUE_TEST_DSN.
 */
#[CoversClass(DatabaseDriver::class)]
#[CoversMethod(DatabaseDriver::class, 'reserve')]
final class DatabaseDriverReserveAttemptsTest extends TestCase
{
    /**
     * Проверим, что reserve() увеличивает attempts в БД и в выданной задаче: первая выдача — попытка 1.
     *
     * @see DatabaseDriver::reserve()
     */
    #[Test]
    public function reserveCountsAttempt(): void
    {
        $database = new QueueDatabase();

        try {
            $queue = $database->driver();
            $queue->push(QueueJob::fromPayload(['task' => 'x'], 'job-1'));

            $reserved = $queue->reserve();

            self::assertNotNull($reserved);
            self::assertSame(1, $reserved->attempts());
            self::assertSame(1, $database->storedAttempts('job-1'));
        } finally {
            $database->close();
        }
    }

    /**
     * Проверим, что резервирование без acknowledge()/release() (воркер упал) учитывается: после visibility
     * timeout задача выдаётся как следующая попытка, а не с прежним числом попыток.
     *
     * @see DatabaseDriver::reserve()
     */
    #[Test]
    public function lostReservationCountsAsAttempt(): void
    {
        $database = new QueueDatabase();

        try {
            $queue = $database->driver();
            $queue->push(QueueJob::fromPayload(['task' => 'x'], 'job-1'));

            // Воркер зарезервировал задачу и умер, не подтвердив её.
            $queue->reserve();
            $database->expireReservations();

            $again = $queue->reserve();

            self::assertNotNull($again);
            self::assertSame(2, $again->attempts());
            self::assertSame(2, $database->storedAttempts('job-1'));
        } finally {
            $database->close();
        }
    }
}
