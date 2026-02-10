<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use function get_debug_type;
use function is_array;
use function is_scalar;
use function sprintf;

final class UnsupportedQueuePayloadException extends QueueException
{
    public static function forPayload(mixed $payload): self
    {
        return new self(sprintf('Unsupported queue job payload: %s', self::resolvePayloadType($payload)));
    }

    private static function resolvePayloadType(mixed $payload): string
    {
        if (is_array($payload) && isset($payload['_job']) && is_scalar($payload['_job'])) {
            return (string) $payload['_job'];
        }

        return get_debug_type($payload);
    }
}
