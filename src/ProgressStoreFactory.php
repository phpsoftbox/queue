<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue;

use InvalidArgumentException;
use PhpSoftBox\Database\Connection\ConnectionManagerInterface;
use PhpSoftBox\Queue\Drivers\DatabaseProgressStore;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;

use function array_key_exists;
use function get_object_vars;
use function is_array;
use function is_string;
use function strtolower;
use function trim;

final class ProgressStoreFactory
{
    /**
     * @param array<string, mixed> $config Settings of the queue section, not the entire application config.
     */
    public function create(array $config = [], ?ConnectionManagerInterface $connections = null): ProgressStoreInterface
    {
        $driver = array_key_exists('progress_driver', $config) ? $config['progress_driver'] : 'database';
        if (!is_string($driver)) {
            throw new InvalidArgumentException('queue.progress_driver must be one of: database, memory, null (string).');
        }

        return match (strtolower(trim($driver))) {
            'memory'   => new InMemoryProgressStore(),
            'null'     => new NullProgressStore(),
            'database' => $this->database($config, $connections),
            default    => throw new InvalidArgumentException('queue.progress_driver must be one of: database, memory, null (string).'),
        };
    }

    /** @param array<string, mixed> $config */
    private function database(array $config, ?ConnectionManagerInterface $connections): DatabaseProgressStore
    {
        if ($connections === null) {
            throw new InvalidArgumentException('queue.progress_driver=database requires ConnectionManagerInterface.');
        }
        $connection = $config['progress_connection'] ?? $config['connection'] ?? 'default';
        if (!is_string($connection) || trim($connection) === '') {
            throw new InvalidArgumentException('queue.progress_connection (or queue.connection) must be a non-empty string.');
        }
        $schemaConfig = $config['progress_schema'] ?? [];
        if (!is_array($schemaConfig)) {
            throw new InvalidArgumentException('queue.progress_schema must be an array.');
        }
        $values = get_object_vars(new DatabaseQueueProgressSchema());
        foreach ($values as $key => $default) {
            $value = $schemaConfig[$key] ?? $default;
            if (!is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('queue.progress_schema.' . $key . ' must be a non-empty string.');
            }
            $values[$key] = $value;
        }

        return new DatabaseProgressStore($connections, new DatabaseQueueProgressSchema(...$values), $connection);
    }
}
