# Changelog

## Unreleased

### Added

- `QueueJobLostException`: задача зарезервирована больше `maxAttempts` раз без результата обработчика (воркер
  падал посреди неё или она выполнялась дольше visibility timeout) — `Worker` не запускает её, а отправляет в failed.
- `queue:listen`: корректная остановка по SIGTERM/SIGINT (расширение `pcntl`) — текущая задача доделывается;
  опции `--max-time` (секунды) и `--memory` (мегабайты), выход после текущей задачи при превышении.
- `WorkerStopCondition` (сигналы, лимиты времени и памяти) и параметр `Worker::run(..., shouldStop:)`,
  проверяемый перед каждой задачей.

- `NullProgressStore` для явного отключения хранения прогресса и запросов отмены.
- `Drivers\InMemoryProgressStore` с изолированным состоянием экземпляра, независимыми
  snapshots и явными `forget()`/`clear()`.
- `ProgressStoreFactory`: независимый `progress_driver`, выбор database/memory/null,
  сохранение настроек соединения и `DatabaseQueueProgressSchema`.
- Общие контрактные тесты DB/memory, тесты фабрики, worker/reporter и конкурентной отмены.

### Fixed

- Задача, роняющая процесс (OOM, fatal error, `kill -9`), перезапускалась бесконечно: `DatabaseDriver::reserve()`
  не увеличивал `attempts`. Теперь попытка засчитывается при резервировании, и после `maxAttempts` задача уходит
  в failed.
- Ошибка `ProgressStoreInterface::start()`, записи статуса `processing` или слушателя события до запуска
  обработчика больше не оставляет задачу зарезервированной до visibility timeout: она сразу возвращается в очередь.

- `DatabaseDriver::push()` с mutex внутри транзакции приложения на PostgreSQL: конфликт ключа больше
  не обрывает транзакцию и даёт `QueueMutexConflictException` вместо `QueryException`.
- `Worker`: ошибка записи статуса или слушателя `JobStatusChangedEvent` после успешного обработчика больше
  не отправляет задачу на повтор (раньше она выполнялась ещё раз); при ошибке на статусе `retrying` задача
  всё равно возвращается в очередь, а не остаётся зарезервированной.
- Неизвестный/отрицательный процент больше не записывает NULL в NOT NULL колонку БД.
- Повторные и конкурентные запросы отмены используют атомарный UPSERT вместо
  предположения об отсутствии записи при нулевом affected rows.

### Changed

- **BREAKING:** `QueueJob::attempts()` — номер текущей попытки, а не число завершённых неудачных. `reserve()` и
  `pop()` (`DatabaseDriver`, `InMemoryDriver`) увеличивают `attempts` при выдаче задачи; `release()` сохраняет
  переданное значение; `Worker` больше не вызывает `withAttempt()` при повторе. Собственные реализации
  `QueueInterface`/`QueueReservationAwareInterface` должны увеличивать счётчик так же. Схема БД не меняется,
  миграция не нужна: сохранённые `attempts` старых задач (число неудач) продолжаются следующим номером попытки.
- `DatabaseDriver::reserve()` обновляет `attempts` вместе с `reserved_datetime` одним `UPDATE`.

- Progress-store использует общий `Clock::now()` и поддерживает существующую заморозку
  времени. Добавлена зависимость `phpsoftbox/clock`.
- Публичные интерфейсы, конструкторы существующих потребителей и DB-схема не изменены.
  Фабрика — дополнительный API; [инструкция перехода](docs/10-progress-stores-upgrade.md).
