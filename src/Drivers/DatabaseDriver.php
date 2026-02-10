<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use JsonException;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Database\Contracts\ConnectionInterface;
use PhpSoftBox\Database\Driver\DriversEnum;
use PhpSoftBox\Database\Exception\ConfigurationException;
use PhpSoftBox\Queue\DatabaseQueueMutexSchema;
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueMutexAwareInterface;
use PhpSoftBox\Queue\QueueMutexConflictException;
use PhpSoftBox\Queue\QueueReservationAwareInterface;
use Throwable;

use function array_key_exists;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function method_exists;
use function sprintf;
use function str_contains;
use function strtolower;
use function time;
use function trim;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class DatabaseDriver implements QueueInterface, QueueMutexAwareInterface, QueueReservationAwareInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseQueueSchema $schema = new DatabaseQueueSchema(),
        private readonly string $connectionName = 'default',
        private readonly DatabaseQueueMutexSchema $mutexSchema = new DatabaseQueueMutexSchema(),
        private readonly int $defaultMutexTtlSeconds = 3600,
        private readonly int $visibilityTimeoutSeconds = 300,
    ) {
    }

    public function push(QueueJob $job): void
    {
        $this->withReconnectRetry(function () use ($job): void {
            $conn = $this->connections->write($this->connectionName);

            if ($conn->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $mutexAcquired = false;
            if ($job->mutexKey() !== null) {
                $mutexAcquired = $this->acquireMutex($conn, $job);
                if (!$mutexAcquired) {
                    throw new QueueMutexConflictException((string) $job->mutexKey());
                }
            }

            $payload     = $this->encodePayload($job->payload());
            $table       = $conn->table($this->schema->table);
            $createdAt   = new DateTimeImmutable()->format('Y-m-d H:i:s');
            $availableAt = $this->formatTimestamp($job->availableAt() ?? time());
            $priority    = $job->priority();

            $sql = sprintf(
                'INSERT INTO %s (%s, %s, %s, %s, %s, %s, %s, %s, %s) VALUES (:job_id, :payload, :attempts, :priority, :available_datetime, :created_datetime, :mutex_key, :mutex_ttl_seconds, :is_cancellable)',
                $table,
                $this->schema->jobIdColumn,
                $this->schema->payloadColumn,
                $this->schema->attemptsColumn,
                $this->schema->priorityColumn,
                $this->schema->availableDatetimeColumn,
                $this->schema->createdDatetimeColumn,
                $this->schema->mutexKeyColumn,
                $this->schema->mutexTtlSecondsColumn,
                $this->schema->isCancellableColumn,
            );

            try {
                $conn->execute($sql, [
                    'job_id'             => $job->id(),
                    'payload'            => $payload,
                    'attempts'           => $job->attempts(),
                    'priority'           => $priority,
                    'available_datetime' => $availableAt,
                    'created_datetime'   => $createdAt,
                    'mutex_key'          => $this->normalizeMutexKey($job->mutexKey()),
                    'mutex_ttl_seconds'  => $job->mutexTtlSeconds(),
                    'is_cancellable'     => $job->isCancellable() ? 1 : 0,
                ]);
            } catch (Throwable $exception) {
                if ($mutexAcquired) {
                    try {
                        $this->releaseMutex($job);
                    } catch (Throwable) {
                    }
                }

                throw $exception;
            }
        }, 'write');
    }

    public function pop(): ?QueueJob
    {
        $job = $this->reserve();
        if ($job === null) {
            return null;
        }

        $this->acknowledge($job);

        return $job;
    }

    public function reserve(): ?QueueJob
    {
        return $this->withReconnectRetry(function (): ?QueueJob {
            $conn = $this->connections->write($this->connectionName);

            if ($conn->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            return $conn->transaction(function (ConnectionInterface $conn): ?QueueJob {
                $table             = $conn->table($this->schema->table);
                $idColumn          = $this->schema->idColumn;
                $availableAtColumn = $this->schema->availableDatetimeColumn;
                $reservedAtColumn  = $this->schema->reservedDatetimeColumn;
                $priorityColumn    = $this->schema->priorityColumn;
                $nowTs             = time();
                $now               = $this->formatTimestamp($nowTs);

                $sql = sprintf(
                    'SELECT * FROM %s WHERE %s <= :now AND (%s IS NULL OR %s <= :now) ORDER BY %s DESC, %s ASC LIMIT 1%s',
                    $table,
                    $availableAtColumn,
                    $reservedAtColumn,
                    $reservedAtColumn,
                    $priorityColumn,
                    $idColumn,
                    $this->lockSuffix($conn),
                );

                $row = $conn->fetchOne($sql, ['now' => $now]);
                if ($row === null) {
                    return null;
                }

                if (!isset($row[$this->schema->jobIdColumn])) {
                    throw new QueueException('Queue row does not contain job id.');
                }

                $visibilityTtl = $this->resolveVisibilityTimeout();
                $conn->execute(
                    sprintf(
                        'UPDATE %s SET %s = :reserved_datetime WHERE %s = :id',
                        $table,
                        $reservedAtColumn,
                        $idColumn,
                    ),
                    [
                        'reserved_datetime' => $this->formatTimestamp($nowTs + $visibilityTtl),
                        'id'                => $row[$idColumn],
                    ],
                );

                return $this->hydrateJob($row);
            });
        }, 'write');
    }

    public function acknowledge(QueueJob $job): void
    {
        $this->withReconnectRetry(function () use ($job): void {
            $conn = $this->connections->write($this->connectionName);

            if ($conn->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $table = $conn->table($this->schema->table);
            $conn->execute(
                sprintf(
                    'DELETE FROM %s WHERE %s = :job_id',
                    $table,
                    $this->schema->jobIdColumn,
                ),
                ['job_id' => $job->id()],
            );
        }, 'write');
    }

    public function release(QueueJob $job, int $delaySeconds = 0): void
    {
        $this->withReconnectRetry(function () use ($job, $delaySeconds): void {
            $conn = $this->connections->write($this->connectionName);

            if ($conn->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $availableAt = $job->availableAt() ?? time();
            if ($delaySeconds > 0) {
                $availableAt = time() + $delaySeconds;
            }

            $table = $conn->table($this->schema->table);
            $conn->execute(
                sprintf(
                    'UPDATE %s SET %s = :payload, %s = :attempts, %s = :priority, %s = :available_datetime, %s = :reserved_datetime, %s = :mutex_key, %s = :mutex_ttl_seconds, %s = :is_cancellable WHERE %s = :job_id',
                    $table,
                    $this->schema->payloadColumn,
                    $this->schema->attemptsColumn,
                    $this->schema->priorityColumn,
                    $this->schema->availableDatetimeColumn,
                    $this->schema->reservedDatetimeColumn,
                    $this->schema->mutexKeyColumn,
                    $this->schema->mutexTtlSecondsColumn,
                    $this->schema->isCancellableColumn,
                    $this->schema->jobIdColumn,
                ),
                [
                    'payload'            => $this->encodePayload($job->payload()),
                    'attempts'           => $job->attempts(),
                    'priority'           => $job->priority(),
                    'available_datetime' => $this->formatTimestamp($availableAt),
                    'reserved_datetime'  => null,
                    'mutex_key'          => $this->normalizeMutexKey($job->mutexKey()),
                    'mutex_ttl_seconds'  => $job->mutexTtlSeconds(),
                    'is_cancellable'     => $job->isCancellable() ? 1 : 0,
                    'job_id'             => $job->id(),
                ],
            );
        }, 'write');
    }

    public function size(): int
    {
        return $this->withReconnectRetry(function (): int {
            $conn  = $this->connections->read($this->connectionName);
            $table = $conn->table($this->schema->table);

            $row = $conn->fetchOne(sprintf('SELECT COUNT(*) as cnt FROM %s', $table));
            if (!is_array($row)) {
                return 0;
            }

            return (int) ($row['cnt'] ?? 0);
        }, 'read');
    }

    public function releaseMutex(QueueJob $job): void
    {
        $mutexKey = $this->normalizeMutexKey($job->mutexKey());
        if ($mutexKey === null) {
            return;
        }

        $this->withReconnectRetry(function () use ($job, $mutexKey): void {
            $conn = $this->connections->write($this->connectionName);

            if ($conn->isReadOnly()) {
                throw new QueueException('Database connection is read-only.');
            }

            $table = $conn->table($this->mutexSchema->table);
            $conn->execute(
                sprintf(
                    'DELETE FROM %s WHERE %s = :mutex_key AND %s = :owner_job_id',
                    $table,
                    $this->mutexSchema->mutexKeyColumn,
                    $this->mutexSchema->ownerJobIdColumn,
                ),
                [
                    'mutex_key'    => $mutexKey,
                    'owner_job_id' => $job->id(),
                ],
            );
        }, 'write');
    }

    private function hydrateJob(array $row): QueueJob
    {
        return new QueueJob(
            (string) $row[$this->schema->jobIdColumn],
            $this->decodePayload($row[$this->schema->payloadColumn] ?? null),
            (int) ($row[$this->schema->attemptsColumn] ?? 0),
            $this->parseTimestamp($row[$this->schema->availableDatetimeColumn] ?? null),
            (int) ($row[$this->schema->priorityColumn] ?? 0),
            $this->resolveNullableString($row, $this->schema->mutexKeyColumn),
            $this->resolveNullablePositiveInt($row, $this->schema->mutexTtlSecondsColumn),
            $this->resolveBool($row[$this->schema->isCancellableColumn] ?? null),
        );
    }

    private function resolveVisibilityTimeout(): int
    {
        $resolved = $this->visibilityTimeoutSeconds;

        return $resolved > 0 ? $resolved : 300;
    }

    private function resolveBool(mixed $value): bool
    {
        if (is_numeric($value)) {
            return (int) $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 't', 'yes', 'y'], true);
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function withReconnectRetry(callable $operation, string $mode): mixed
    {
        try {
            return $operation();
        } catch (Throwable $exception) {
            if (!$this->isConnectionLost($exception)) {
                throw $exception;
            }

            $this->tryReconnect($mode);

            return $operation();
        }
    }

    private function tryReconnect(string $mode): void
    {
        if (!method_exists($this->connections, 'reconnect')) {
            return;
        }

        if ($mode === 'read') {
            try {
                $this->connections->reconnect($this->connectionName . '.read');

                return;
            } catch (ConfigurationException) {
            } catch (Throwable) {
                return;
            }
        }

        if ($mode === 'write') {
            try {
                $this->connections->reconnect($this->connectionName . '.write');

                return;
            } catch (ConfigurationException) {
            } catch (Throwable) {
                return;
            }
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
            if (in_array($code, ['2006', '2013'], true)) {
                return true;
            }

            $message = strtolower($current->getMessage());
            if (
                str_contains($message, 'server has gone away')
                || str_contains($message, 'lost connection')
                || str_contains($message, 'server closed the connection unexpectedly')
                || str_contains($message, 'no connection to the server')
                || str_contains($message, 'is dead or not enabled')
            ) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }

    private function lockSuffix(ConnectionInterface $conn): string
    {
        $driver = $conn->driver()->name();

        if (in_array($driver, [DriversEnum::MYSQL->value, DriversEnum::MARIADB->value, DriversEnum::POSTGRES->value, 'postgres'], true)) {
            return ' FOR UPDATE SKIP LOCKED';
        }

        return '';
    }

    private function encodePayload(mixed $payload): string
    {
        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to encode queue payload.', 0, $exception);
        }

        return $json;
    }

    private function decodePayload(mixed $payload): mixed
    {
        if ($payload === null) {
            return null;
        }

        if (!is_string($payload)) {
            return $payload;
        }

        try {
            return json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to decode queue payload.', 0, $exception);
        }
    }

    private function acquireMutex(ConnectionInterface $conn, QueueJob $job): bool
    {
        $mutexKey = $this->normalizeMutexKey($job->mutexKey());
        if ($mutexKey === null) {
            return true;
        }

        $nowTs       = time();
        $now         = $this->formatTimestamp($nowTs);
        $ttl         = $job->mutexTtlSeconds();
        $resolvedTtl = is_int($ttl) && $ttl > 0 ? $ttl : $this->defaultMutexTtlSeconds;
        $resolvedTtl = $resolvedTtl > 0 ? $resolvedTtl : 3600;
        $expiresAt   = $this->formatTimestamp($nowTs + $resolvedTtl);
        $table       = $conn->table($this->mutexSchema->table);

        $conn->execute(
            sprintf(
                'DELETE FROM %s WHERE %s = :mutex_key AND %s <= :now',
                $table,
                $this->mutexSchema->mutexKeyColumn,
                $this->mutexSchema->expiresDatetimeColumn,
            ),
            [
                'mutex_key' => $mutexKey,
                'now'       => $now,
            ],
        );

        try {
            $conn->execute(
                sprintf(
                    'INSERT INTO %s (%s, %s, %s, %s, %s) VALUES (:mutex_key, :owner_job_id, :expires_datetime, :created_datetime, :updated_datetime)',
                    $table,
                    $this->mutexSchema->mutexKeyColumn,
                    $this->mutexSchema->ownerJobIdColumn,
                    $this->mutexSchema->expiresDatetimeColumn,
                    $this->mutexSchema->createdDatetimeColumn,
                    $this->mutexSchema->updatedDatetimeColumn,
                ),
                [
                    'mutex_key'        => $mutexKey,
                    'owner_job_id'     => $job->id(),
                    'expires_datetime' => $expiresAt,
                    'created_datetime' => $now,
                    'updated_datetime' => $now,
                ],
            );

            return true;
        } catch (Throwable) {
        }

        $existing = $conn->fetchOne(
            sprintf(
                'SELECT %s, %s FROM %s WHERE %s = :mutex_key LIMIT 1',
                $this->mutexSchema->ownerJobIdColumn,
                $this->mutexSchema->expiresDatetimeColumn,
                $table,
                $this->mutexSchema->mutexKeyColumn,
            ),
            ['mutex_key' => $mutexKey],
        );

        if (!is_array($existing)) {
            return false;
        }

        $ownerRaw  = $existing[$this->mutexSchema->ownerJobIdColumn] ?? null;
        $owner     = is_string($ownerRaw) ? $ownerRaw : '';
        $expiresTs = $this->parseTimestamp($existing[$this->mutexSchema->expiresDatetimeColumn] ?? null);
        if ($owner !== $job->id() && ($expiresTs === null || $expiresTs > $nowTs)) {
            return false;
        }

        $conn->execute(
            sprintf(
                'UPDATE %s SET %s = :owner_job_id, %s = :expires_datetime, %s = :updated_datetime WHERE %s = :mutex_key',
                $table,
                $this->mutexSchema->ownerJobIdColumn,
                $this->mutexSchema->expiresDatetimeColumn,
                $this->mutexSchema->updatedDatetimeColumn,
                $this->mutexSchema->mutexKeyColumn,
            ),
            [
                'owner_job_id'     => $job->id(),
                'expires_datetime' => $expiresAt,
                'updated_datetime' => $now,
                'mutex_key'        => $mutexKey,
            ],
        );

        return true;
    }

    private function normalizeMutexKey(?string $mutexKey): ?string
    {
        if (!is_string($mutexKey)) {
            return null;
        }

        $mutexKey = trim($mutexKey);

        return $mutexKey !== '' ? $mutexKey : null;
    }

    private function formatTimestamp(int $timestamp): string
    {
        return new DateTimeImmutable()->setTimestamp($timestamp)->format('Y-m-d H:i:s');
    }

    private function parseTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $this->parseStringTimestamp($value);
        }

        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function parseStringTimestamp(string $value): ?int
    {
        try {
            return new DateTimeImmutable($value)->getTimestamp();
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveNullableString(array $row, string $key): ?string
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }

        $value = $row[$key];
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function resolveNullablePositiveInt(array $row, string $key): ?int
    {
        if (!array_key_exists($key, $row)) {
            return null;
        }

        $value = $row[$key];
        if (!is_numeric($value)) {
            return null;
        }

        $resolved = (int) $value;

        return $resolved > 0 ? $resolved : null;
    }
}
