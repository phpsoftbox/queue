<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

final class QueueProgressStatus
{
    public const string QUEUED     = 'queued';
    public const string PROCESSING = 'processing';
    public const string RETRYING   = 'retrying';
    public const string COMPLETED  = 'completed';
    public const string FAILED     = 'failed';
    public const string CANCELLED  = 'cancelled';

    private function __construct()
    {
    }
}
