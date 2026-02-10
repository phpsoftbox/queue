<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Queue\ProgressReporter;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProgressReporter::class)]
final class ProgressReporterTest extends TestCase
{
    /**
     * Проверяет, что репортер отправляет обновления processed по шагу процента.
     */
    #[Test]
    public function updatesProcessedOnlyByPercentStep(): void
    {
        $store = new class () implements ProgressStoreInterface {
            /** @var list<array{processed:int,percent:?int}> */
            public array $processedCalls = [];

            public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
            {
            }

            public function setTotal(string $jobId, ?int $total): void
            {
            }

            public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
            {
                $this->processedCalls[] = ['processed' => $processed, 'percent' => $percent];
            }

            public function setStatus(string $jobId, string $status, ?string $error = null): void
            {
            }

            public function setMeta(string $jobId, array $meta): void
            {
            }

            public function requestCancellation(string $jobId): bool
            {
                return false;
            }

            public function isCancellationRequested(string $jobId): bool
            {
                return false;
            }

            public function snapshot(string $jobId): ?QueueProgressSnapshot
            {
                return null;
            }
        };

        $progress = new ProgressReporter($store, 'job-1', stepPercent: 10);

        $progress->setTotal(100);

        for ($i = 0; $i < 20; $i++) {
            $progress->increment();
        }

        self::assertSame([
            ['processed' => 0, 'percent' => 0],
            ['processed' => 10, 'percent' => 10],
            ['processed' => 20, 'percent' => 20],
        ], $store->processedCalls);
    }
}
