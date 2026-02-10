<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use InvalidArgumentException;

use function get_debug_type;
use function sprintf;

final readonly class CompositeQueueJobHandler implements QueueJobHandlerInterface
{
    /**
     * @var list<QueuePayloadHandlerInterface>
     */
    private array $handlers;

    /**
     * @param iterable<QueuePayloadHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers)
    {
        $this->handlers = $this->normalizeHandlers($handlers);
    }

    public function handle(mixed $payload, QueueJob $job): void
    {
        foreach ($this->handlers as $handler) {
            if (!$handler->supports($payload)) {
                continue;
            }

            $handler->handle($payload);

            return;
        }

        throw UnsupportedQueuePayloadException::forPayload($payload);
    }

    /**
     * @param iterable<QueuePayloadHandlerInterface> $handlers
     *
     * @return list<QueuePayloadHandlerInterface>
     */
    private function normalizeHandlers(iterable $handlers): array
    {
        $normalized = [];
        foreach ($handlers as $handler) {
            if (!$handler instanceof QueuePayloadHandlerInterface) {
                throw new InvalidArgumentException(sprintf(
                    'Composite queue handler expects %s instances, %s given.',
                    QueuePayloadHandlerInterface::class,
                    get_debug_type($handler),
                ));
            }

            $normalized[] = $handler;
        }

        return $normalized;
    }
}
