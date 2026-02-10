<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

interface QueuePayloadHandlerInterface
{
    public function supports(mixed $payload): bool;

    public function handle(mixed $payload): void;
}
