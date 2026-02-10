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
        private readonly ?string $mutexKey = null,
        private readonly ?int $mutexTtlSeconds = null,
        private readonly bool $isCancellable = false,
    ) {
    }

    public static function fromPayload(
        mixed $payload,
        ?string $id = null,
        int $priority = 0,
        ?int $availableAt = null,
        ?string $mutexKey = null,
        ?int $mutexTtlSeconds = null,
        bool $isCancellable = false,
    ): self {
        $id ??= bin2hex(random_bytes(8));

        return new self($id, $payload, 0, $availableAt, $priority, $mutexKey, $mutexTtlSeconds, $isCancellable);
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

    public function mutexKey(): ?string
    {
        return $this->mutexKey;
    }

    public function mutexTtlSeconds(): ?int
    {
        return $this->mutexTtlSeconds;
    }

    public function isCancellable(): bool
    {
        return $this->isCancellable;
    }

    public function withAttempt(): self
    {
        return new self(
            $this->id,
            $this->payload,
            $this->attempts + 1,
            $this->availableAt,
            $this->priority,
            $this->mutexKey,
            $this->mutexTtlSeconds,
            $this->isCancellable,
        );
    }

    public function withDelay(int $seconds): self
    {
        $timestamp = time() + $seconds;

        return new self(
            $this->id,
            $this->payload,
            $this->attempts,
            $timestamp,
            $this->priority,
            $this->mutexKey,
            $this->mutexTtlSeconds,
            $this->isCancellable,
        );
    }

    public function withPriority(int $priority): self
    {
        return new self(
            $this->id,
            $this->payload,
            $this->attempts,
            $this->availableAt,
            $priority,
            $this->mutexKey,
            $this->mutexTtlSeconds,
            $this->isCancellable,
        );
    }

    public function withMutex(string $mutexKey, ?int $ttlSeconds = null): self
    {
        return new self(
            $this->id,
            $this->payload,
            $this->attempts,
            $this->availableAt,
            $this->priority,
            $mutexKey,
            $ttlSeconds,
            $this->isCancellable,
        );
    }

    public function withCancellable(bool $isCancellable = true): self
    {
        return new self(
            $this->id,
            $this->payload,
            $this->attempts,
            $this->availableAt,
            $this->priority,
            $this->mutexKey,
            $this->mutexTtlSeconds,
            $isCancellable,
        );
    }
}
