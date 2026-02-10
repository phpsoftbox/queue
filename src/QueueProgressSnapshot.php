<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

final readonly class QueueProgressSnapshot
{
    public function __construct(
        public string $status,
        public int $attempt,
        public ?int $total,
        public int $processed,
        public int $percent,
        public ?string $error,
        /** @var array<string, mixed> */
        public array $meta,
        public ?string $cancelRequestedAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $updatedAt,
    ) {
    }

    /**
     * @return array{
     *   status:string,
     *   attempt:int,
     *   total:?int,
     *   processed:int,
     *   percent:int,
     *   error:?string,
     *   meta:array<string, mixed>,
     *   cancel_requested_at:?string,
     *   started_at:?string,
     *   finished_at:?string,
     *   updated_at:?string
     * }
     */
    public function toArray(): array
    {
        return [
            'status'              => $this->status,
            'attempt'             => $this->attempt,
            'total'               => $this->total,
            'processed'           => $this->processed,
            'percent'             => $this->percent,
            'error'               => $this->error,
            'meta'                => $this->meta,
            'cancel_requested_at' => $this->cancelRequestedAt,
            'started_at'          => $this->startedAt,
            'finished_at'         => $this->finishedAt,
            'updated_at'          => $this->updatedAt,
        ];
    }
}
