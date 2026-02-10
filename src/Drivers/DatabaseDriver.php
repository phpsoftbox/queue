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
use PhpSoftBox\Queue\DatabaseQueueSchema;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueInterface;
use PhpSoftBox\Queue\QueueJob;

use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function sprintf;
use function time;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class DatabaseDriver implements QueueInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseQueueSchema $schema = new DatabaseQueueSchema(),
        private readonly string $connectionName = 'default',
    ) {
    }

    public function push(QueueJob $job): void
    {
        $conn = $this->connections->write($this->connectionName);

        if ($conn->isReadOnly()) {
            throw new QueueException('Database connection is read-only.');
        }

        $payload     = $this->encodePayload($job->payload());
        $table       = $conn->table($this->schema->table);
        $createdAt   = new DateTimeImmutable()->format('Y-m-d H:i:s');
        $availableAt = $this->formatTimestamp($job->availableAt() ?? time());
        $priority    = $job->priority();

        $sql = sprintf(
            'INSERT INTO %s (%s, %s, %s, %s, %s, %s) VALUES (:job_id, :payload, :attempts, :priority, :available_datetime, :created_datetime)',
            $table,
            $this->schema->jobIdColumn,
            $this->schema->payloadColumn,
            $this->schema->attemptsColumn,
            $this->schema->priorityColumn,
            $this->schema->availableDatetimeColumn,
            $this->schema->createdDatetimeColumn,
        );

        $conn->execute($sql, [
            'job_id'             => $job->id(),
            'payload'            => $payload,
            'attempts'           => $job->attempts(),
            'priority'           => $priority,
            'available_datetime' => $availableAt,
            'created_datetime'   => $createdAt,
        ]);
    }

    public function pop(): ?QueueJob
    {
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
            $now               = $this->formatTimestamp(time());

            $sql = sprintf(
                'SELECT * FROM %s WHERE %s <= :now AND (%s IS NULL) ORDER BY %s DESC, %s ASC LIMIT 1%s',
                $table,
                $availableAtColumn,
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

            $conn->execute(
                sprintf('DELETE FROM %s WHERE %s = :id', $table, $idColumn),
                ['id' => $row[$idColumn]],
            );

            return new QueueJob(
                (string) $row[$this->schema->jobIdColumn],
                $this->decodePayload($row[$this->schema->payloadColumn] ?? null),
                (int) ($row[$this->schema->attemptsColumn] ?? 0),
                $this->parseTimestamp($row[$this->schema->availableDatetimeColumn] ?? null),
                (int) ($row[$this->schema->priorityColumn] ?? 0),
            );
        });
    }

    public function size(): int
    {
        $conn  = $this->connections->read($this->connectionName);
        $table = $conn->table($this->schema->table);

        $row = $conn->fetchOne(sprintf('SELECT COUNT(*) as cnt FROM %s', $table));
        if (!is_array($row)) {
            return 0;
        }

        return (int) ($row['cnt'] ?? 0);
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
}
