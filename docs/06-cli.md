# CLI

Команды автоматически регистрируются через `extra.psb.providers` в `composer.json`.

Доступные команды:
- `queue:listen` — запустить обработчик очереди
- `queue:run` — выполнить все доступные задачи один раз
- `queue:push` — добавить задание

Примеры:

```bash
php psb queue:listen --max-jobs=100 --sleep=1
php psb queue:run --debug
php psb queue:push "hello"
```

Для `queue:listen` и `queue:run` требуется сервис `QueueJobHandlerInterface` в DI-контейнере.
Опция `--debug` (`-d`) выводит подробный лог выполнения задач.
