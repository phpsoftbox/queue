# Quick Start

Добавление задания и обработка воркером:

```php
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;

$queue = new InMemoryDriver();
$queue->push(QueueJob::fromPayload('ping')->withCancellable());

$worker = new Worker($queue, maxAttempts: 2);
$worker->run(function (mixed $payload, QueueJob $job, ProgressAwareInterface $progress): void {
    $progress->setTotal(1);
    $progress->increment();
    // обработка
});
```

Обработка ошибок и уведомление о фейле:

```php
$worker = new Worker(
    $queue,
    maxAttempts: 2,
    onFailure: function (QueueJob $job, Throwable $exception): void {
        // логирование или алерт
    }
);
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

Отложенная задача и приоритет:

```php
$job = QueueJob::fromPayload('ping')
    ->withDelay(60)
    ->withPriority(10);

$queue->push($job);
```
