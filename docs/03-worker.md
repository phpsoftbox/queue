# Worker

`Worker` забирает задания из очереди и передаёт payload в обработчик.

Сигнатуры:

```php
$worker = new Worker($queue, maxAttempts: 3, onFailure: $callback);
$processed = $worker->run(function (mixed $payload, QueueJob $job): void {
    // обработка
}, maxJobs: 0);
```

Параметры:
- `maxAttempts` — максимальное число попыток (по умолчанию 3).
- `onFailure` — callback для финальной ошибки.
- `failedStore` — опциональное хранилище для задач, исчерпавших попытки.
- `logger` — опциональный `LoggerInterface` для логирования процесса.
- `run(..., maxJobs)` — число заданий за запуск, `0` = без лимита.

Если обработчик бросает исключение, job возвращается в очередь до достижения `maxAttempts`.
Если задан `failedStore`, задача записывается туда после последней попытки.

## Failed jobs

Пример записи неуспешных задач в БД:

```php
use PhpSoftBox\Queue\DatabaseFailedJobSchema;
use PhpSoftBox\Queue\Drivers\DatabaseFailedJobStore;

$failedStore = new DatabaseFailedJobStore(
    connections: $connectionManager,
    schema: new DatabaseFailedJobSchema(),
    connectionName: 'default',
);

$worker = new Worker($queue, maxAttempts: 3, failedStore: $failedStore);
```
