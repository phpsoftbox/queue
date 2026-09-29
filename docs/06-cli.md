# CLI

Команды автоматически регистрируются через `extra.psb.providers` в `composer.json`.

Доступные команды:
- `queue:listen` — запустить обработчик очереди
- `queue:run` — выполнить все доступные задачи один раз
- `queue:push` — добавить задание

Примеры:

```bash
php psb queue:listen --max-jobs=100 --sleep=1
php psb queue:listen --max-time=3600 --memory=256
php psb queue:run --debug
php psb queue:push "hello"
```

## queue:listen: остановка и лимиты

- `--max-jobs` (`-m`) — выйти после N задач (`0` — без лимита);
- `--max-time` — выйти, когда процесс работает дольше N секунд (`0` — без лимита);
- `--memory` — выйти, когда процесс занял больше N мегабайт (`memory_get_usage(true)`, `0` — без лимита);
- `--sleep` (`-s`) — пауза при пустой очереди, секунды.

SIGTERM и SIGINT (при установленном расширении `pcntl`) не обрывают задачу: воркер доделывает текущую задачу,
подтверждает её и выходит с кодом 0. Лимиты тоже проверяются только между задачами. Сигнал во время паузы при пустой
очереди прерывает паузу. Причина остановки выводится строкой `Queue listener stopped: ...`.

Без `pcntl` сигналы завершают процесс сразу; незавершённая задача вернётся в очередь через visibility timeout
и будет засчитана как попытка.

Команда рассчитана на запуск под супервизором (systemd, supervisord, `restart: unless-stopped` в Docker), который
перезапускает процесс после выхода. Время остановки супервизора (`TimeoutStopSec`, `stopwaitsecs`,
`stop_grace_period`) задавайте больше самой долгой задачи, иначе после SIGTERM процесс будет убит SIGKILL.

Тот же механизм доступен в своём цикле: `WorkerStopCondition` (сигналы, `maxTimeSeconds`, `memoryLimitMegabytes`)
передаётся в `Worker::run(..., shouldStop: $condition->shouldStop(...))`.

Для `queue:listen` и `queue:run` требуется сервис `QueueJobHandlerInterface` в DI-контейнере.
Опция `--debug` (`-d`) выводит подробный лог выполнения задач.
