<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use InvalidArgumentException;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseFailedJobSchema;
use PhpSoftBox\Queue\Drivers\DatabaseFailedJobStore;
use PhpSoftBox\Queue\QueueJob;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function ltrim;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[CoversClass(DatabaseFailedJobStore::class)]
#[CoversClass(DatabaseFailedJobSchema::class)]
final class DatabaseFailedJobStoreTest extends TestCase
{
    /**
     * Проверяет, что в queue_failed_jobs сохраняются класс ошибки, локация и stack trace.
     */
    #[Test]
    public function testStoreSavesExceptionWithLocationAndTrace(): void
    {
        [$manager, $dbFile] = $this->prepareStore();

        try {
            $store = new DatabaseFailedJobStore(
                connections: $manager,
                schema: new DatabaseFailedJobSchema(),
                connectionName: 'main',
            );

            $job = QueueJob::fromPayload(['task' => 'report'], 'job-1');
            $store->store($job, $this->runtimeFailure());

            $row = $manager->connection('main')->fetchOne(
                'SELECT job_id, attempts, exception FROM queue_failed_jobs WHERE job_id = :job_id LIMIT 1',
                ['job_id' => 'job-1'],
            );

            $this->assertNotNull($row);
            $this->assertSame('job-1', (string) ($row['job_id'] ?? null));
            $this->assertSame(0, (int) ($row['attempts'] ?? -1));
            $this->assertStringContainsString('RuntimeException: boom', (string) ($row['exception'] ?? ''));
            $this->assertMatchesRegularExpression('/Location:\s.+:\d+/', (string) ($row['exception'] ?? ''));
            $this->assertStringContainsString('Trace:', (string) ($row['exception'] ?? ''));
            $this->assertStringContainsString('#0', (string) ($row['exception'] ?? ''));
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * Проверяет, что previous-цепочка исключений целиком сохраняется в exception.
     */
    #[Test]
    public function testStoreSavesPreviousExceptionChain(): void
    {
        [$manager, $dbFile] = $this->prepareStore();

        try {
            $store = new DatabaseFailedJobStore(
                connections: $manager,
                schema: new DatabaseFailedJobSchema(),
                connectionName: 'main',
            );

            $exception = new RuntimeException('outer', 0, new InvalidArgumentException('inner'));

            $store->store(QueueJob::fromPayload(['task' => 'chain'], 'job-chain'), $exception);

            $row = $manager->connection('main')->fetchOne(
                'SELECT exception FROM queue_failed_jobs WHERE job_id = :job_id LIMIT 1',
                ['job_id' => 'job-chain'],
            );

            $text = (string) ($row['exception'] ?? '');
            $this->assertStringContainsString('RuntimeException: outer', $text);
            $this->assertStringContainsString('Previous: InvalidArgumentException: inner', $text);
            $this->assertStringContainsString('Trace:', $text);
        } finally {
            @unlink($dbFile);
        }
    }

    /**
     * @return array{ConnectionManagerInterface, string}
     */
    private function prepareStore(): array
    {
        $dbFile = sprintf('%s/psb_queue_failed_%s.sqlite', sys_get_temp_dir(), uniqid('', true));
        $dsn    = 'sqlite:////' . ltrim($dbFile, '/');

        $factory = new DatabaseFactory([
            'connections' => [
                'default' => 'main',
                'main'    => [
                    'read'  => ['dsn' => $dsn],
                    'write' => ['dsn' => $dsn],
                ],
            ],
        ]);

        $manager = new ConnectionManager($factory);

        $manager->connection('main')->schema()->create('queue_failed_jobs', function (TableBlueprint $table): void {
            $table->id();
            $table->string('job_id', 64);
            $table->json('payload');
            $table->integer('attempts')->default(0);
            $table->text('exception');
            $table->datetime('failed_datetime');
        });

        return [$manager, $dbFile];
    }

    private function runtimeFailure(): RuntimeException
    {
        try {
            $this->explodeFailure();
        } catch (RuntimeException $exception) {
            return $exception;
        }

        return new RuntimeException('unexpected');
    }

    private function explodeFailure(): void
    {
        throw new RuntimeException('boom');
    }
}
