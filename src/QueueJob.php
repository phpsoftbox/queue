<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use function bin2hex;
use function random_bytes;
use function time;

final class QueueJob
{
    public function __construct(
        private readonly string $id,
        private readonly mixed $payload,
        private readonly int $attempts = 0,
        private readonly ?int $availableAt = null,
        private readonly int $priority = 0,
    ) {
    }

    public static function fromPayload(mixed $payload, ?string $id = null, int $priority = 0, ?int $availableAt = null): self
    {
        $id ??= bin2hex(random_bytes(8));

        return new self($id, $payload, 0, $availableAt, $priority);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function payload(): mixed
    {
        return $this->payload;
    }

    public function attempts(): int
    {
        return $this->attempts;
    }

    public function availableAt(): ?int
    {
        return $this->availableAt;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function withAttempt(): self
    {
        return new self($this->id, $this->payload, $this->attempts + 1, $this->availableAt, $this->priority);
    }

    public function withDelay(int $seconds): self
    {
        $timestamp = time() + $seconds;

        return new self($this->id, $this->payload, $this->attempts, $timestamp, $this->priority);
    }

    public function withPriority(int $priority): self
    {
        return new self($this->id, $this->payload, $this->attempts, $this->availableAt, $priority);
    }
}
