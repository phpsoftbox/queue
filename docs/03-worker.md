# Worker

`Worker` забирает задания из очереди и передаёт payload в обработчик.

Сигнатуры:

```php
$worker = new Worker(
    $queue,
    maxAttempts: 3,
    onFailure: $callback,
    failedStore: $failedStore,
    logger: $logger,
    progressStore: $progressStore,
    events: $eventDispatcher,
    progressStepPercent: 5,
);

$processed = $worker->run(function (
    mixed $payload,
    QueueJob $job,
    ProgressAwareInterface $progress,
): void {
    // обработка
}, maxJobs: 0);
```

Параметры:
- `maxAttempts` — максимальное число попыток (по умолчанию 3).
- `onFailure` — callback для финальной ошибки.
- `failedStore` — опциональное хранилище для задач, исчерпавших попытки.
- `logger` — опциональный `LoggerInterface` для логирования процесса.
- `progressStore` — backend для прогресса (`ProgressStoreInterface`).
- `events` — `Psr\EventDispatcher\EventDispatcherInterface`.
- `progressStepPercent` — шаг записи прогресса по процентам.
- `run(..., maxJobs)` — число заданий за запуск, `0` = без лимита.

`run()` поддерживает handler с 1/2/3 аргументами:
- `(payload)`
- `(payload, job)`
- `(payload, job, progress)`

Если обработчик бросает исключение, job возвращается в очередь до достижения `maxAttempts`.  
Если задан `failedStore`, задача записывается туда после последней попытки.

## Статусы и события

Worker обновляет прогресс через статусы:
- `processing`
- `retrying`
- `completed`
- `failed`
- `cancelled`

И диспатчит события:
- `JobBeforeEvent`
- `JobAfterEvent`
- `JobStatusChangedEvent`

## Отмена задач

Отмена применяется только для задач с `withCancellable()`:

```php
$queue->push(
    QueueJob::fromPayload($payload, 'job-1')
        ->withMutex('tenant:1:company:15:import')
        ->withCancellable(),
);
```

Если до запуска handler у такой задачи выставлен флаг отмены в `progressStore`, worker:
- не запускает handler;
- ставит статус `cancelled`;
- освобождает mutex.

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
