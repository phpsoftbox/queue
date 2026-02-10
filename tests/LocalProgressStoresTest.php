<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\NullProgressStore;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(NullProgressStore::class)]
#[CoversClass(InMemoryProgressStore::class)]
#[CoversMethod(NullProgressStore::class, 'requestCancellation')]
#[CoversMethod(InMemoryProgressStore::class, 'clear')]
final class LocalProgressStoresTest extends TestCase
{
    /**
     * Проверяет все операции null-store: нет состояния, сериализации meta или успешной отмены.
     * @see NullProgressStore::snapshot()
     */
    #[Test]
    public function nullStoreDoesNothing(): void
    {
        $store = new NullProgressStore();

        $store->start('job');
        $store->setTotal('job', 10);
        $store->setProcessed('job', 4, 40);
        $store->setStatus('job', 'failed', 'error');
        $store->setMeta('job', ['invalid-json' => "\xFF"]);
        self::assertFalse($store->requestCancellation('job'));
        self::assertFalse($store->isCancellationRequested('job'));
        self::assertNull($store->snapshot('job'));
    }

    /**
     * Проверяет независимость экземпляров и выборочную/полную очистку без изменения старых snapshots.
     * @see InMemoryProgressStore::forget()
     * @see InMemoryProgressStore::clear()
     */
    #[Test]
    public function memoryCanBeClearedExplicitly(): void
    {
        $store = new InMemoryProgressStore();
        $other = new InMemoryProgressStore();
        $store->start('a');
        $store->start('b');
        $store->setStatus('a', 'completed');
        $snapshot = $store->snapshot('a');
        self::assertNotNull($snapshot);
        self::assertNull($other->snapshot('a'));
        $store->forget(' a ');
        self::assertNull($store->snapshot('a'));
        self::assertNotNull($store->snapshot('b'));
        $store->clear();
        self::assertNull($store->snapshot('b'));
        self::assertSame('completed', $snapshot->status);
    }
}
