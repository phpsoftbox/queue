<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Drivers;

use DateTimeImmutable;
use JsonException;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Queue\DatabaseFailedJobSchema;
use PhpSoftBox\Queue\FailedJobStoreInterface;
use PhpSoftBox\Queue\QueueException;
use PhpSoftBox\Queue\QueueJob;
use Throwable;

use function json_encode;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class DatabaseFailedJobStore implements FailedJobStoreInterface
{
    public function __construct(
        private readonly ConnectionManagerInterface $connections,
        private readonly DatabaseFailedJobSchema $schema = new DatabaseFailedJobSchema(),
        private readonly string $connectionName = 'default',
    ) {
    }

    public function store(QueueJob $job, Throwable $exception): void
    {
        $conn = $this->connections->write($this->connectionName);

        if ($conn->isReadOnly()) {
            throw new QueueException('Database connection is read-only.');
        }

        $table         = $conn->table($this->schema->table);
        $payload       = $this->encodePayload($job->payload());
        $failedAt      = new DateTimeImmutable()->format('Y-m-d H:i:s');
        $exceptionInfo = sprintf(
            '%s: %s',
            $exception::class,
            $exception->getMessage(),
        );

        $sql = sprintf(
            'INSERT INTO %s (%s, %s, %s, %s, %s) VALUES (:job_id, :payload, :attempts, :exception, :failed_datetime)',
            $table,
            $this->schema->jobIdColumn,
            $this->schema->payloadColumn,
            $this->schema->attemptsColumn,
            $this->schema->exceptionColumn,
            $this->schema->failedDatetimeColumn,
        );

        $conn->execute($sql, [
            'job_id'          => $job->id(),
            'payload'         => $payload,
            'attempts'        => $job->attempts(),
            'exception'       => $exceptionInfo,
            'failed_datetime' => $failedAt,
        ]);
    }

    private function encodePayload(mixed $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new QueueException('Failed to encode queue payload.', 0, $exception);
        }
    }
}
