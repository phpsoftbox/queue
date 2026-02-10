# Прогресс и отмена заданий

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

Для `DatabaseProgressStore` и `InMemoryProgressStore` обновление статуса в `completed`
не перезаписывает уже выставленные terminal-статусы `failed`/`cancelled`.

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

## Что и где хранится

`ProgressReporter` вычисляет проценты и определяет, когда сохранять обновление.
`ProgressStoreInterface` хранит результат и запрос отмены. `QueueProgressSnapshot`
возвращает состояние: статус, попытку, total/processed/percent, ошибку, meta и даты.
Store не запускает задачи и не знает, каким драйвером пользуется очередь.

Встроены три реализации:

| Драйвер | Реализация | Область состояния | Запрос отмены |
| --- | --- | --- | --- |
| `database` | `Drivers\DatabaseProgressStore` | Общая SQL-БД | Сохраняется для worker |
| `memory` | `Drivers\InMemoryProgressStore` | Один экземпляр в одном процессе | Доступен через тот же экземпляр |
| `null` | `NullProgressStore` | Ничего не хранит | Всегда `false` |

У null-store `snapshot()` всегда возвращает `null`, setter-ы ничего не делают.
Это не успешная отмена и не временный fallback на случай ошибки подключения.
`NullProgressReporter` — другой объект: он реализует API обработчика, а не хранилища.
Worker без `progressStore`, как и раньше, создаёт именно такой reporter.

## Фабрика и конфигурация

```php
use PhpSoftBox\Queue\ProgressStoreFactory;

$store = new ProgressStoreFactory()->create(
    config: [
        'driver' => 'memory', // фабрика прогресса не использует этот ключ
        'connection' => 'default',
        'progress_driver' => 'database',
        'progress_connection' => 'progress',
        'progress_schema' => [
            'table' => 'task_progress',
            'jobIdColumn' => 'task_id',
        ],
    ],
    connections: $connections, // ConnectionManagerInterface
);
```

В `config` передаётся сама секция `queue`, не корневая конфигурация приложения.
Фабрика не читает environment и не зависит от DI/config-пакетов.

- Нет ключа `progress_driver` — выбирается `database` независимо от `driver`.
- Значение строковое, пробелы по краям и регистр не важны.
- Допустимы только `database`, `memory`, строка `'null'`.
- PHP `null`, пустая строка, иной тип или неизвестное имя — `InvalidArgumentException`.
- Для `database` обязателен `ConnectionManagerInterface`. Само создание store не
  открывает соединение. Ошибки SQL, отсутствующей таблицы и read-only БД не скрываются.
- Для `memory`/`null` менеджер соединений не нужен и не используется даже при передаче.
  DB-специфичные настройки в этих режимах не применяются.
- Соединение выбирается по `progress_connection` → `connection` → `default`;
  PHP `null` в ключе соединения пропускается. Выбранное имя должно быть непустой строкой.
- `progress_schema` использует имена параметров `DatabaseQueueProgressSchema`:
  `table`, `jobIdColumn`, `statusColumn`, `totalColumn`, `processedColumn`, `percentColumn`,
  `stepPercentColumn`, `attemptColumn`, `errorColumn`, `metaColumn`,
  `cancelRequestedDatetimeColumn`, `startedDatetimeColumn`, `finishedDatetimeColumn`,
  `createdDatetimeColumn`, `updatedDatetimeColumn`.
  Пропущенные/null-параметры сохраняют значения по умолчанию. Остальные должны быть
  непустыми строками. Неизвестные ключи, как и при прежнем ручном подключении, игнорируются.

Каждый `create()` создаёт новый объект. Совместное использование memory-store
обеспечивает приложение (например, общий сервис в контейнере), а не фабрика.
Настройки `FailedJobStoreInterface` не меняются: отключение прогресса не отключает
сохранение неуспешных заданий и не гарантирует, что всему приложению не нужна БД.

## Пример memory-store

```php
use PhpSoftBox\Queue\Drivers\InMemoryDriver;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;
use PhpSoftBox\Queue\ProgressAwareInterface;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\Worker;

$queue = new InMemoryDriver();
$store = new InMemoryProgressStore();
$queue->push(new QueueJob('import-1', null)->withCancellable());

$worker = new Worker($queue, progressStore: $store);
$worker->run(static function (mixed $payload, QueueJob $job, ProgressAwareInterface $progress): void {
    $progress->setTotal(2);
    $progress->increment();
    $progress->increment();
});

$snapshot = $store->snapshot('import-1'); // completed, processed=2, percent=100
$store->forget('import-1'); // после того, как результат больше не нужен
$store->clear();           // явная очистка всей истории этого экземпляра
```

Память теряется при рестарте. Новый экземпляр пуст. Из HTTP-процесса нельзя
прочитать состояние memory-store другого worker или отправить ему отмену.
Для этого нужен database-store с общей БД. В долгоживущем процессе освобождайте
ненужную историю через `forget()`/`clear()`; автоматического удаления при завершении нет.

## Контракт состояния

- `jobId` нормализуется через `trim()`. Пустой идентификатор игнорируется;
  snapshot для неизвестного задания — `null`, запрос отмены для пустого — `false`.
- `start()` создаёт `queued`, нулевые счётчики, `total=null`, пустые error/meta.
  Начало и обновление получают текущее время. Номер попытки меньше 1 заменяется на 1.
- Повторный `start()` сбрасывает результат, ошибку и finishedAt, обновляет начало и
  попытку, но сохраняет meta и запрос отмены.
- Обычные setter-ы не создают неизвестное задание. `setMeta([])` ничего не меняет.
- Отрицательный total становится `null`; отрицательный processed — `0`.
  Неизвестный/отрицательный percent сохраняется как `0`. Store не пересчитывает процент
  и не ограничивает переданное положительное число значением 100; это делает reporter.
- `setMeta()` поверхностно объединяет ключи. Вложенный массив заменяется целиком.
  Значения проходят JSON-преобразование: объекты становятся данными, а не живыми ссылками.
  Некорректный JSON вызывает `QueueException`. Порядок ключей JSON не является контрактом.
- Каждый snapshot независим от последующих изменений store и исходных объектов meta.
- `completed`, `failed`, `cancelled` устанавливают finishedAt; остальные непустые статусы
  очищают его. Пустой статус игнорируется. Ошибка в snapshot обрезается по краям;
  пустая ошибка представляется `null`. Произвольные непустые статусы разрешены.

При неизвестном или нулевом total reporter сохраняет каждый processed и передаёт
неизвестный процент. При положительном total применяет `progressStepPercent` и
ограничивает рассчитанный процент значением 100. Достижение 100% публикуется независимо
от шага. Новый reporter на retry начинает счётчики заново.

## Семантика отмены

`requestCancellation($jobId)` возвращает `true`, если запрос записан, а не если
обработчик уже остановлен. Если записи ещё нет, создаётся `queued` со `startedAt=null`.
Повторный запрос обновляет отметку отмены. Статус, счётчики и meta существующей записи
сохраняются. Следующий `start()` и retry не стирают флаг.

SQL-store использует атомарный UPSERT для SQLite, PostgreSQL, MariaDB/MySQL.
Для `jobIdColumn` нужен уникальный индекс, уже присутствующий в штатной миграции.
Параллельные запросы отмены не создают дубликатов. Read-подключение должно видеть
актуальное состояние write-подключения: асинхронная реплика может задержать видимость
запроса отмены, поэтому для немедленной проверки используйте основной сервер.

Worker проверяет флаг перед handler только у cancellable-заданий. При отмене
не вызывает handler, записывает `cancelled`, подтверждает зарезервированное задание
и освобождает mutex. Во время выполнения отмена кооперативная: handler сам проверяет
`$progress->isCancellationRequested()` в безопасных точках, освобождает ресурсы,
выставляет `cancelled` и выходит. Принудительного прерывания PHP-процесса нет.

## Управляемое время и тесты

Оба хранилища получают время через `PhpSoftBox\Clock\Clock::now()`. Формат остался
`Y-m-d H:i:s`; без замороженного времени используется timezone PHP-приложения.
Конструкторы не требуют отдельного clock-аргумента. Существующий механизм
заморозки времени TestUtils работает и с этими store.

```php
use PhpSoftBox\Clock\Clock;
use PhpSoftBox\Queue\Drivers\InMemoryProgressStore;

Clock::freeze(new DateTimeImmutable('2026-09-21 12:00:00'));
try {
    $store = new InMemoryProgressStore();
    $store->start('job');
    Clock::travel(60);
    $store->setStatus('job', 'completed');
    // startedAt: 12:00:00; finishedAt: 12:01:00, без sleep().
} finally {
    Clock::reset();
}
```

`vendor/bin/phpunit` запускает компонентные тесты; DB-контракт по умолчанию проверяется
на временной SQLite-БД, memory-тесты соединений не создают. Для новых DB-контрактов,
фабрики, reporter и теста конкурентной отмены можно задать `QUEUE_TEST_DSN`, например:

```text
QUEUE_TEST_DSN=mariadb://queue:queue@db-test:3306/queue_tests
QUEUE_TEST_DSN=postgres://queue:queue@db-test:5432/queue_tests
```

Используйте только отдельную тестовую БД: тесты создают случайно именованные таблицы
и удаляют их в teardown. Старые DB-тесты компонента продолжают использовать свою SQLite.
Тест конкурентной отмены запускает четыре PHP-процесса через `proc_open()` и проверяет
повторные запросы с одинаковым временем. Зависимости: PDO-драйвер выбранной БД и
разрешённый запуск дочернего PHP; реальных задержек через `sleep()` нет.
