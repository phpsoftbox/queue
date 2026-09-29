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
- `run(..., shouldStop: fn (): bool => ...)` — проверяется перед каждой задачей; `true` завершает `run()`, не беря
  следующую задачу (текущая всегда доделывается). Так `queue:listen` реализует выход по сигналу и лимитам, см.
  `WorkerStopCondition` в [CLI](06-cli.md).

`run()` поддерживает handler с 1/2/3 аргументами:
- `(payload)`
- `(payload, job)`
- `(payload, job, progress)`

Если обработчик бросает исключение, job возвращается в очередь до достижения `maxAttempts`.  
Если задан `failedStore`, задача записывается туда после последней попытки.

## Попытки и упавший воркер

`QueueJob::attempts()` — номер текущей попытки. Драйвер увеличивает его при выдаче задачи (`reserve()`/`pop()`), а не
по результату обработчика, поэтому выданная воркеру задача всегда имеет `attempts() >= 1`, и в логе, прогрессе,
`failedStore` и `onFailure` пишется именно этот номер.

Так учитываются и попытки, которые не закончились ничем: воркер умер посреди задачи (OOM, fatal error, `kill -9`) —
задача осталась зарезервированной, через visibility timeout её снова выдаёт `reserve()` уже со следующим номером.
Если номер больше `maxAttempts`, `Worker` не запускает обработчик: задача получает статус `failed`, передаётся в
`failedStore` и `onFailure` с `QueueJobLostException` («worker lost / visibility timeout exceeded»), снимается с
очереди и освобождает mutex. Раньше такая задача перезапускалась бесконечно и роняла каждый следующий воркер.

Попытка засчитывается и когда задача выполнялась дольше visibility timeout и её забрал другой воркер — выбирайте
`visibilityTimeoutSeconds` по [правилам Database Queue](05-database.md#видимость-зарезервированной-задачи).

`maxAttempts` меньше 1 считается равным 1.

Если подготовка задачи до запуска обработчика упала (`ProgressStoreInterface::start()`, запись статуса
`processing`, слушатель `JobStatusChangedEvent`/`JobBeforeEvent`), задача сразу возвращается в очередь
(`release()`/`push()` без задержки), а ошибка пробрасывается из `run()`. Раньше задача оставалась зарезервированной
до visibility timeout. Попытка при этом уже засчитана драйвером.

Повтор вызывает только исключение обработчика. Если обработчик завершился успешно, а упал учёт после него
(запись прогресса, слушатель `JobStatusChangedEvent`), задача всё равно подтверждается и снимается с очереди, а
ошибка пробрасывается из `run()` — задача не выполняется повторно. При ошибке учёта на статусе `retrying` задача
всё равно возвращается в очередь.

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
