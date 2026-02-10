# Progress

Компонент поддерживает единый контракт прогресса задач, без привязки к конкретному хранилищу.

## Контракты

- `ProgressAwareInterface` — API, доступное из handler задачи.
- `ProgressStoreInterface` — backend для хранения состояния прогресса (SQL/Redis/Mongo и т.д.).

Методы `ProgressAwareInterface`:
- `setTotal(?int $total)` — задать общее количество единиц обработки.
- `increment(int $amount = 1)` — увеличить обработанное количество.
- `setProcessed(int $processed)` — установить обработанное количество явно.
- `setStatus(string $status, ?string $error = null)` — изменить статус.
- `setMeta(array $meta)` — обновить метаданные.
- `isCancellationRequested()` — проверить флаг отмены.

## Статусы прогресса

`QueueProgressStatus`:
- `queued`
- `processing`
- `retrying`
- `completed`
- `failed`
- `cancelled`

Для `DatabaseProgressStore` обновление статуса в `completed` не перезаписывает уже выставленные terminal-статусы `failed`/`cancelled`.

## Worker + progress

`Worker` принимает:
- `progressStore` — реализация `ProgressStoreInterface`.
- `progressStepPercent` — шаг обновления (например `5` => запись прогресса каждые 5%).
- `events` — `EventDispatcherInterface` для lifecycle-событий.

Сигнатура handler поддерживает 1/2/3 аргумента:

```php
$worker->run(function (
    mixed $payload,
    QueueJob $job,
    ProgressAwareInterface $progress,
): void {
    $progress->setTotal(1000);
    $progress->increment();
});
```

## Отмена

Отмена применяется только к задачам с флагом `is_cancellable`:

```php
$job = QueueJob::fromPayload($payload)->withCancellable();
```

Если `isCancellationRequested()` возвращает `true` до старта handler:
- handler не запускается;
- статус выставляется в `cancelled`.

## События

При запуске worker диспатчит:
- `JobBeforeEvent`
- `JobAfterEvent`
- `JobStatusChangedEvent`

События можно использовать для интеграций (websocket-пуш прогресса, метрики, аудит).
