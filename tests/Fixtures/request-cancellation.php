<?php

declare(strict_types=1);

use PhpSoftBox\Clock\Clock;
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Queue\DatabaseQueueProgressSchema;
use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;

require __DIR__ . '/../../vendor/autoload.php';

$input   = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
$manager = new ConnectionManager(new DatabaseFactory([
    'connections' => ['default' => 'test', 'test' => ['read' => ['dsn' => $input['dsn']], 'write' => ['dsn' => $input['dsn']]]],
]));

$manager->write();
Clock::freeze(new DateTimeImmutable('2026-09-21 12:00:00'));
$store = new DatabaseProgressStore($manager, new DatabaseQueueProgressSchema(table: $input['table']));
echo "ready\n";
flush();
if (trim(fgets(STDIN)) !== 'go') {
    exit(1);
}
for ($i = 0; $i < 20; $i++) {
    if (!$store->requestCancellation('concurrent-job')) {
        exit(2);
    }
}
