<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PHPUnit\Framework\Attributes\{CoversClass, CoversMethod, Test};
use PHPUnit\Framework\TestCase;

use function fclose;
use function fgets;
use function fwrite;
use function is_resource;
use function json_encode;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_get_contents;
use function stream_set_timeout;

use const JSON_THROW_ON_ERROR;
use const PHP_BINARY;

#[CoversClass(DatabaseProgressStore::class)]
#[CoversMethod(DatabaseProgressStore::class, 'requestCancellation')]
final class ProgressStoreConcurrencyTest extends TestCase
{
    /**
     * Проверяет одновременную отмену отсутствующего задания четырьмя процессами без дублей и ошибок.
     * @see DatabaseProgressStore::requestCancellation()
     */
    #[Test]
    public function concurrentCancellationCreatesOneRecord(): void
    {
        $database = new ProgressDatabase();
        $children = [];
        try {
            for ($i = 0; $i < 4; $i++) {
                $process = proc_open([PHP_BINARY, __DIR__ . '/Fixtures/request-cancellation.php'], [
                    0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
                ], $pipes);
                self::assertIsResource($process);
                $children[] = [$process, $pipes];
                stream_set_timeout($pipes[1], 15);
                stream_set_timeout($pipes[2], 15);
                fwrite($pipes[0], json_encode(['dsn' => $database->dsn, 'table' => $database->schema->table], JSON_THROW_ON_ERROR) . "\n");
            }
            foreach ($children as [, $pipes]) {
                self::assertSame("ready\n", fgets($pipes[1]), 'Child did not reach the start barrier.');
            }
            foreach ($children as [, $pipes]) {
                fwrite($pipes[0], "go\n");
                fclose($pipes[0]);
            }
            foreach ($children as [$process, $pipes]) {
                self::assertSame('', stream_get_contents($pipes[1]));
                self::assertSame('', stream_get_contents($pipes[2]));
                self::assertSame(0, proc_close($process));
            }
            $store = new DatabaseProgressStore($database->connections, $database->schema, 'progress');

            self::assertTrue($store->isCancellationRequested('concurrent-job'));
            $rows = $database->connections->read('progress')->query()->select()->from($database->schema->table)->fetchAll();
            self::assertCount(1, $rows);
        } finally {
            foreach ($children as [$process, $pipes]) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($process)) {
                    proc_terminate($process);
                    proc_close($process);
                }
            }
            $database->close();
        }
    }
}
