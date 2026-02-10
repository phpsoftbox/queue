# PhpSoftBox Queue

## About
`phpsoftbox/queue` — компонент очередей и воркера для PhpSoftBox. Включает минимальный контракт очереди, модель job, in-memory очередь и адаптер для работы с БД.

Ключевые свойства:
- контракт `QueueInterface`
- модель `QueueJob` с попытками
- `Worker` с ретраями и обработкой ошибок
- `DatabaseDriver` для использования БД через компонент Database
- поддержка приоритета и отложенной доступности (available_datetime)
- запись задач, исчерпавших попытки, через `FailedJobStoreInterface`

## Quick Start
```php
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;

$queue = new InMemoryDriver();
$queue->push(QueueJob::fromPayload(['type' => 'email', 'id' => 10]));

$worker = new Worker($queue, maxAttempts: 3);
$worker->run(function (mixed $payload): void {
    // обработка задания
});
```

Очередь в БД:

```php
use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\DatabaseQueueSchema;

$factory = new DatabaseFactory([
    'connections' => [
        'default' => 'main',
        'main' => [
            'read' => ['dsn' => 'sqlite:///:memory:'],
            'write' => ['dsn' => 'sqlite:///:memory:'],
        ],
    ],
]);

$queue = new DatabaseDriver(new ConnectionManager($factory), new DatabaseQueueSchema(), 'main');
```

## Оглавление
- [Документация](docs/index.md)
- [About](docs/01-about.md)
- [Quick Start](docs/02-quick-start.md)
- [Worker](docs/03-worker.md)
- [InMemory Queue](docs/04-in-memory.md)
- [Database Queue](docs/05-database.md)
- [CLI](docs/06-cli.md)
- [DI](docs/07-di.md)
