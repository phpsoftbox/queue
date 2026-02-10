<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use InvalidArgumentException;
use PhpSoftBox\Queue\CompositeQueueJobHandler;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueuePayloadHandlerInterface;
use PhpSoftBox\Queue\UnsupportedQueuePayloadException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(CompositeQueueJobHandler::class)]
#[CoversClass(UnsupportedQueuePayloadException::class)]
final class CompositeQueueJobHandlerTest extends TestCase
{
    #[Test]
    public function testDispatchesPayloadToFirstSupportedHandler(): void
    {
        $first  = new RecordingPayloadHandler(false);
        $second = new RecordingPayloadHandler(true);
        $third  = new RecordingPayloadHandler(true);

        $handler = new CompositeQueueJobHandler([$first, $second, $third]);

        $payload = ['_job' => 'tenant.provision', 'id' => 15];
        $handler->handle($payload, QueueJob::fromPayload($payload, 'job-1'));

        self::assertSame([], $first->handledPayloads);
        self::assertSame([$payload], $second->handledPayloads);
        self::assertSame([], $third->handledPayloads);
    }

    #[Test]
    public function testThrowsPayloadExceptionWhenNoHandlerSupportsPayload(): void
    {
        $handler = new CompositeQueueJobHandler([new RecordingPayloadHandler(false)]);

        $this->expectException(UnsupportedQueuePayloadException::class);
        $this->expectExceptionMessage('Unsupported queue job payload: product.import');

        $handler->handle(['_job' => 'product.import'], QueueJob::fromPayload([], 'job-1'));
    }

    #[Test]
    public function testThrowsPayloadExceptionWithDebugTypeForPayloadWithoutJobType(): void
    {
        $handler = new CompositeQueueJobHandler([]);

        $this->expectException(UnsupportedQueuePayloadException::class);
        $this->expectExceptionMessage('Unsupported queue job payload: stdClass');

        $handler->handle((object) ['id' => 1], QueueJob::fromPayload([], 'job-1'));
    }

    #[Test]
    public function testRejectsInvalidHandler(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(QueuePayloadHandlerInterface::class);

        new CompositeQueueJobHandler([new stdClass()]);
    }
}

final class RecordingPayloadHandler implements QueuePayloadHandlerInterface
{
    /**
     * @var list<mixed>
     */
    public array $handledPayloads = [];

    public function __construct(
        private readonly bool $supported,
    ) {
    }

    public function supports(mixed $payload): bool
    {
        return $this->supported;
    }

    public function handle(mixed $payload): void
    {
        $this->handledPayloads[] = $payload;
    }
}
