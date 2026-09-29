# Database Queue

`DatabaseDriver` использует компонент `phpsoftbox/database` и хранит задания в таблице БД.

```php
use PhpSoftBox\Queue\Drivers\DatabaseDriver;
use PhpSoftBox\Queue\DatabaseQueueSchema;

$queue = new DatabaseDriver(
    connections: $connectionManager,
    schema: new DatabaseQueueSchema(),
    connectionName: 'default',
);
```

## Схема таблицы

Имена таблицы и колонок можно переопределить через `DatabaseQueueSchema`:

```php
$schema = new DatabaseQueueSchema(
    table: 'queue_jobs',
    idColumn: 'id',
    jobIdColumn: 'job_id',
    payloadColumn: 'payload',
    attemptsColumn: 'attempts',
    priorityColumn: 'priority',
    availableDatetimeColumn: 'available_datetime',
    reservedDatetimeColumn: 'reserved_datetime',
    createdDatetimeColumn: 'created_datetime',
);
```

Колонки:
- `priority` — приоритет (больше = выше).
- `available_datetime` — время, когда задача доступна.
- `reserved_datetime` — резерв (пока не используется, оставлено для будущего).

## Миграция

В пакете есть пример миграции:

```
packages/Queue/migrations/20250101000000_create_queue_jobs_table.php
```

Таблица для упавших задач:

```
packages/Queue/migrations/20250101000001_create_queue_failed_jobs_table.php
```

Колонки по умолчанию: `job_id`, `payload`, `attempts`, `exception`, `failed_datetime`.

Для записи неуспешных задач используйте `DatabaseFailedJobStore`
и `DatabaseFailedJobSchema` (см. `Worker`).

Таблица прогресса задач:

```
migrations/20250101000003_create_queue_progress_table.php
```

Для SQL-хранилища прогресса используйте:
- `DatabaseProgressStore`
- `DatabaseQueueProgressSchema`

Если вы меняете имена колонок, создайте собственную миграцию под вашу схему.

## Mutex и транзакции

`push()` задачи с `withMutex()` можно вызывать внутри транзакции приложения: занятый mutex даёт
`QueueMutexConflictException`, а транзакция остаётся рабочей — вставка в `queue_mutexes` не бросает ошибку
при дубликате (`ON CONFLICT DO NOTHING` / `ON DUPLICATE KEY UPDATE`), поэтому в PostgreSQL она не обрывает
внешнюю транзакцию. Колонка ключа mutex должна быть уникальной (как в миграции пакета).

## Видимость зарезервированной задачи

`reserve()` скрывает задачу на `visibilityTimeoutSeconds` (по умолчанию 300 секунд). Если воркер не подтвердил
задачу за это время (упал или задача выполняется дольше), её заберёт следующий `reserve()` — задача выполнится
повторно. Для долгих задач увеличьте `visibilityTimeoutSeconds` в конструкторе `DatabaseDriver` с запасом
больше максимального времени выполнения.
