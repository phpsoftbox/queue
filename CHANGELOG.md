# Changelog

## Unreleased

### Added

- `NullProgressStore` для явного отключения хранения прогресса и запросов отмены.
- `Drivers\InMemoryProgressStore` с изолированным состоянием экземпляра, независимыми
  snapshots и явными `forget()`/`clear()`.
- `ProgressStoreFactory`: независимый `progress_driver`, выбор database/memory/null,
  сохранение настроек соединения и `DatabaseQueueProgressSchema`.
- Общие контрактные тесты DB/memory, тесты фабрики, worker/reporter и конкурентной отмены.

### Fixed

- Неизвестный/отрицательный процент больше не записывает NULL в NOT NULL колонку БД.
- Повторные и конкурентные запросы отмены используют атомарный UPSERT вместо
  предположения об отсутствии записи при нулевом affected rows.

### Changed

- Progress-store использует общий `Clock::now()` и поддерживает существующую заморозку
  времени. Добавлена зависимость `phpsoftbox/clock`.
- Публичные интерфейсы, конструкторы существующих потребителей и DB-схема не изменены.
  Фабрика — дополнительный API; [инструкция перехода](docs/10-progress-stores-upgrade.md).
