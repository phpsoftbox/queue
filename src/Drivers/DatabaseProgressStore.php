<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use DateTimeImmutable;
use JsonException;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Queue\DatabaseQueueProgressSchema;
use PhpSoftBox\Queue\ProgressStoreInterface;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueProgressSnapshot;
use PhpSoftBox\Queue\QueueProgressStatus;
use Throwable;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class DatabaseProgressStore implements ProgressStoreInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseQueueProgressSchema $schema = new DatabaseQueueProgressSchema(),
        private readonly string $connectionName = 'default',
    ) {
    }

    public function start(string $jobId, int $attempt = 1, int $stepPercent = 1): void
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return;
        }

        $resolvedAttempt = $attempt > 0 ? $attempt : 1;
        $resolvedStep    = $stepPercent > 0 ? $stepPercent : 1;
        $now             = $this->now();

        $this->withReconnectRetry(function () use ($resolvedJobId, $resolvedAttempt, $resolvedStep, $now): void {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $existing = $connection->fetchOne(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :job_id LIMIT 1',
                    $this->schema->jobIdColumn,
                    $connection->table($this->schema->table),
                    $this->schema->jobIdColumn,
                ),
                ['job_id' => $resolvedJobId],
            );

            if (!is_array($existing)) {
                $connection->execute(
                    sprintf(
                        'INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s) VALUES (:job_id, :status, :total, :processed, :percent, :step_percent, :attempt, :started_datetime, :created_datetime, :updated_datetime)',
                        $connection->table($this->schema->table),
                        $this->schema->jobIdColumn,
                        $this->schema->statusColumn,
                        $this->schema->totalColumn,
                        $this->schema->processedColumn,
                        $this->schema->percentColumn,
                        $this->schema->stepPercentColumn,
                        $this->schema->attemptColumn,
                        $this->schema->startedDatetimeColumn,
                        $this->schema->createdDatetimeColumn,
                        $this->schema->updatedDatetimeColumn,
                    ),
                    [
                        'job_id'           => $resolvedJobId,
                        'status'           => QueueProgressStatus::QUEUED,
                        'total'            => null,
                        'processed'        => 0,
                        'percent'          => 0,
                        'step_percent'     => $resolvedStep,
                        'attempt'          => $resolvedAttempt,
                        'started_datetime' => $now,
                        'created_datetime' => $now,
                        'updated_datetime' => $now,
                    ],
                );

                return;
            }

            $connection->execute(
                sprintf(
                    'UPDATE %s SET %s = :status, %s = :total, %s = :processed, %s = :percent, %s = :step_percent, %s = :attempt, %s = :error, %s = :started_datetime, %s = :finished_datetime, %s = :updated_datetime WHERE %s = :job_id',
                    $connection->table($this->schema->table),
                    $this->schema->statusColumn,
                    $this->schema->totalColumn,
                    $this->schema->processedColumn,
                    $this->schema->percentColumn,
                    $this->schema->stepPercentColumn,
                    $this->schema->attemptColumn,
                    $this->schema->errorColumn,
                    $this->schema->startedDatetimeColumn,
                    $this->schema->finishedDatetimeColumn,
                    $this->schema->updatedDatetimeColumn,
                    $this->schema->jobIdColumn,
                ),
                [
                    'status'            => QueueProgressStatus::QUEUED,
                    'total'             => null,
                    'processed'         => 0,
                    'percent'           => 0,
                    'step_percent'      => $resolvedStep,
                    'attempt'           => $resolvedAttempt,
                    'error'             => null,
                    'started_datetime'  => $now,
                    'finished_datetime' => null,
                    'updated_datetime'  => $now,
                    'job_id'            => $resolvedJobId,
                ],
            );
        });
    }

    public function setTotal(string $jobId, ?int $total): void
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return;
        }

        $resolvedTotal = $total !== null && $total >= 0 ? $total : null;
        $this->withReconnectRetry(function () use ($resolvedJobId, $resolvedTotal): void {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $connection->execute(
                sprintf(
                    'UPDATE %s SET %s = :total, %s = :updated_datetime WHERE %s = :job_id',
                    $connection->table($this->schema->table),
                    $this->schema->totalColumn,
                    $this->schema->updatedDatetimeColumn,
                    $this->schema->jobIdColumn,
                ),
                [
                    'total'            => $resolvedTotal,
                    'updated_datetime' => $this->now(),
                    'job_id'           => $resolvedJobId,
                ],
            );
        });
    }

    public function setProcessed(string $jobId, int $processed, ?int $percent = null): void
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return;
        }

        $resolvedProcessed = $processed >= 0 ? $processed : 0;
        $resolvedPercent   = $percent !== null && $percent >= 0 ? $percent : null;
        $this->withReconnectRetry(function () use ($resolvedJobId, $resolvedProcessed, $resolvedPercent): void {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $sql = sprintf(
                'UPDATE %s SET %s = :processed, %s = :percent, %s = :updated_datetime WHERE %s = :job_id',
                $connection->table($this->schema->table),
                $this->schema->processedColumn,
                $this->schema->percentColumn,
                $this->schema->updatedDatetimeColumn,
                $this->schema->jobIdColumn,
            );

            $connection->execute($sql, [
                'processed'        => $resolvedProcessed,
                'percent'          => $resolvedPercent,
                'updated_datetime' => $this->now(),
                'job_id'           => $resolvedJobId,
            ]);
        });
    }

    public function setStatus(string $jobId, string $status, ?string $error = null): void
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return;
        }

        $resolvedStatus = trim($status);
        if ($resolvedStatus === '') {
            return;
        }

        $now              = $this->now();
        $finishedDatetime = null;
        if (in_array($resolvedStatus, [
            QueueProgressStatus::FAILED,
            QueueProgressStatus::COMPLETED,
            QueueProgressStatus::CANCELLED,
        ], true)) {
            $finishedDatetime = $now;
        }

        $this->withReconnectRetry(function () use ($resolvedJobId, $resolvedStatus, $error, $finishedDatetime, $now): void {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $where    = $this->schema->jobIdColumn . ' = :job_id';
            $bindings = [
                'status'            => $resolvedStatus,
                'error'             => $error,
                'finished_datetime' => $finishedDatetime,
                'updated_datetime'  => $now,
                'job_id'            => $resolvedJobId,
            ];

            if ($resolvedStatus === QueueProgressStatus::COMPLETED) {
                // Не перезаписываем финальные статусы, выставленные handler-ом вручную.
                $where .= ' AND ' . $this->schema->statusColumn . ' NOT IN (:guard_failed, :guard_cancelled)';
                $bindings['guard_failed']    = QueueProgressStatus::FAILED;
                $bindings['guard_cancelled'] = QueueProgressStatus::CANCELLED;
            }

            $connection->execute(
                sprintf(
                    'UPDATE %s SET %s = :status, %s = :error, %s = :finished_datetime, %s = :updated_datetime WHERE %s',
                    $connection->table($this->schema->table),
                    $this->schema->statusColumn,
                    $this->schema->errorColumn,
                    $this->schema->finishedDatetimeColumn,
                    $this->schema->updatedDatetimeColumn,
                    $where,
                ),
                $bindings,
            );
        });
    }

    public function setMeta(string $jobId, array $meta): void
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '' || $meta === []) {
            return;
        }

        $this->withReconnectRetry(function () use ($resolvedJobId, $meta): void {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $row = $connection->fetchOne(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :job_id LIMIT 1',
                    $this->schema->metaColumn,
                    $connection->table($this->schema->table),
                    $this->schema->jobIdColumn,
                ),
                ['job_id' => $resolvedJobId],
            );
            $existing = is_array($row)
                ? $this->decodeMeta($row[$this->schema->metaColumn] ?? null)
                : [];
            $payload = [...$existing, ...$meta];

            $connection->execute(
                sprintf(
                    'UPDATE %s SET %s = :meta, %s = :updated_datetime WHERE %s = :job_id',
                    $connection->table($this->schema->table),
                    $this->schema->metaColumn,
                    $this->schema->updatedDatetimeColumn,
                    $this->schema->jobIdColumn,
                ),
                [
                    'meta'             => $this->encodeMeta($payload),
                    'updated_datetime' => $this->now(),
                    'job_id'           => $resolvedJobId,
                ],
            );
        });
    }

    public function requestCancellation(string $jobId): bool
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return false;
        }

        return $this->withReconnectRetry(function () use ($resolvedJobId): bool {
            $connection = $this->connections->write($this->connectionName);
            if ($connection->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $now      = $this->now();
            $affected = $connection->execute(
                sprintf(
                    'UPDATE %s SET %s = :cancel_requested_datetime, %s = :updated_datetime WHERE %s = :job_id',
                    $connection->table($this->schema->table),
                    $this->schema->cancelRequestedDatetimeColumn,
                    $this->schema->updatedDatetimeColumn,
                    $this->schema->jobIdColumn,
                ),
                [
                    'cancel_requested_datetime' => $now,
                    'updated_datetime'          => $now,
                    'job_id'                    => $resolvedJobId,
                ],
            );

            if ($affected > 0) {
                return true;
            }

            $connection->execute(
                sprintf(
                    'INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s) VALUES (:job_id, :status, :total, :processed, :percent, :step_percent, :attempt, :cancel_requested_datetime, :started_datetime, :created_datetime, :updated_datetime, :finished_datetime)',
                    $connection->table($this->schema->table),
                    $this->schema->jobIdColumn,
                    $this->schema->statusColumn,
                    $this->schema->totalColumn,
                    $this->schema->processedColumn,
                    $this->schema->percentColumn,
                    $this->schema->stepPercentColumn,
                    $this->schema->attemptColumn,
                    $this->schema->cancelRequestedDatetimeColumn,
                    $this->schema->startedDatetimeColumn,
                    $this->schema->createdDatetimeColumn,
                    $this->schema->updatedDatetimeColumn,
                    $this->schema->finishedDatetimeColumn,
                ),
                [
                    'job_id'                    => $resolvedJobId,
                    'status'                    => QueueProgressStatus::QUEUED,
                    'total'                     => null,
                    'processed'                 => 0,
                    'percent'                   => 0,
                    'step_percent'              => 1,
                    'attempt'                   => 1,
                    'cancel_requested_datetime' => $now,
                    'started_datetime'          => null,
                    'created_datetime'          => $now,
                    'updated_datetime'          => $now,
                    'finished_datetime'         => null,
                ],
            );

            return true;
        });
    }

    public function isCancellationRequested(string $jobId): bool
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return false;
        }

        return $this->withReconnectRetry(function () use ($resolvedJobId): bool {
            $connection = $this->connections->read($this->connectionName);

            $row = $connection->fetchOne(
                sprintf(
                    'SELECT %s FROM %s WHERE %s = :job_id LIMIT 1',
                    $this->schema->cancelRequestedDatetimeColumn,
                    $connection->table($this->schema->table),
                    $this->schema->jobIdColumn,
                ),
                ['job_id' => $resolvedJobId],
            );

            return is_array($row) && $row[$this->schema->cancelRequestedDatetimeColumn] !== null;
        });
    }

    public function snapshot(string $jobId): ?QueueProgressSnapshot
    {
        $resolvedJobId = trim($jobId);
        if ($resolvedJobId === '') {
            return null;
        }

        return $this->withReconnectRetry(function () use ($resolvedJobId): ?QueueProgressSnapshot {
            $connection = $this->connections->read($this->connectionName);

            $row = $connection->fetchOne(
                sprintf(
                    'SELECT %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s FROM %s WHERE %s = :job_id LIMIT 1',
                    $this->schema->statusColumn,
                    $this->schema->attemptColumn,
                    $this->schema->totalColumn,
                    $this->schema->processedColumn,
                    $this->schema->percentColumn,
                    $this->schema->errorColumn,
                    $this->schema->metaColumn,
                    $this->schema->cancelRequestedDatetimeColumn,
                    $this->schema->startedDatetimeColumn,
                    $this->schema->finishedDatetimeColumn,
                    $this->schema->updatedDatetimeColumn,
                    $connection->table($this->schema->table),
                    $this->schema->jobIdColumn,
                ),
                ['job_id' => $resolvedJobId],
            );

            if (!is_array($row)) {
                return null;
            }

            $status = trim((string) ($row[$this->schema->statusColumn] ?? ''));
            if ($status === '') {
                $status = QueueProgressStatus::QUEUED;
            }

            return new QueueProgressSnapshot(
                status: $status,
                attempt: $this->resolvePositiveInt($row[$this->schema->attemptColumn] ?? null, 1),
                total: $this->resolveNullableInt($row[$this->schema->totalColumn] ?? null),
                processed: $this->resolveInt($row[$this->schema->processedColumn] ?? 0),
                percent: $this->resolveInt($row[$this->schema->percentColumn] ?? 0),
                error: $this->resolveNullableString($row[$this->schema->errorColumn] ?? null),
                meta: $this->decodeMeta($row[$this->schema->metaColumn] ?? null),
                cancelRequestedAt: $this->resolveNullableString($row[$this->schema->cancelRequestedDatetimeColumn] ?? null),
                startedAt: $this->resolveNullableString($row[$this->schema->startedDatetimeColumn] ?? null),
                finishedAt: $this->resolveNullableString($row[$this->schema->finishedDatetimeColumn] ?? null),
                updatedAt: $this->resolveNullableString($row[$this->schema->updatedDatetimeColumn] ?? null),
            );
        });
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withReconnectRetry(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (!$this->isConnectionLost($exception)) {
                throw $exception;
            }

            $this->tryReconnect();

            return $operation();
        }
    }

    private function tryReconnect(): void
    {
        if (!method_exists($this->connections, 'reconnect')) {
            return;
        }

        try {
            $this->connections->reconnect($this->connectionName . '.write');

            return;
        } catch (ConfigurationException) {
        } catch (Throwable) {
            return;
        }

        try {
            $this->connections->reconnect($this->connectionName);
        } catch (Throwable) {
        }
    }

    private function isConnectionLost(Throwable $exception): bool
    {
        $current = $exception;
        while ($current instanceof Throwable) {
            $code = (string) $current->getCode();
            if ($code === '2006' || $code === '2013') {
                return true;
            }

            $message = strtolower($current->getMessage());
            if (
                str_contains($message, 'server has gone away')
                || str_contains($message, 'lost connection')
                || str_contains($message, 'server closed the connection unexpectedly')
                || str_contains($message, 'no connection to the server')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function encodeMeta(array $meta): string
    {
        try {
            $json = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to encode queue progress meta.', 0, $exception);
        }

        return $json;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMeta(mixed $meta): array
    {
        if (!is_string($meta) || trim($meta) === '') {
            return [];
        }

        try {
            $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to decode queue progress meta.', 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function now(): string
    {
        return new DateTimeImmutable()->format('Y-m-d H:i:s');
    }

    private function resolveNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function resolveInt(mixed $value): int
    {
        if (!is_numeric($value)) {
            return 0;
        }

        return (int) $value;
    }

    private function resolvePositiveInt(mixed $value, int $fallback): int
    {
        if (!is_numeric($value)) {
            return $fallback;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : $fallback;
    }

    private function resolveNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $resolved = trim($value);

        return $resolved !== '' ? $resolved : null;
    }
}
