# Composite Handler

`Worker` вызывает один callable/handler для каждого job. В приложении обычно
удобнее держать отдельный обработчик на каждый тип payload, а в worker передать
один общий handler. Для этого в компоненте есть:

- `QueueJobHandlerInterface` — boundary для worker-а: `handle(mixed $payload, QueueJob $job)`;
- `QueuePayloadHandlerInterface` — маленький application-level обработчик payload;
- `CompositeQueueJobHandler` — выбирает первый payload-handler, который поддерживает payload;
- `UnsupportedQueuePayloadException` — явная ошибка, если payload не поддержан.

## Payload handler

```php
use PhpSoftBox\Queue\QueuePayloadHandlerInterface;

final readonly class TenantProvisionPayloadHandler implements QueuePayloadHandlerInterface
{
    public function supports(mixed $payload): bool
    {
        return is_array($payload) && ($payload['_job'] ?? null) === 'tenant.provision';
    }

    public function handle(mixed $payload): void
    {
        $tenantId = (string) $payload['tenant_id'];

        // provisioning tenant database...
    }
}
```

`supports()` должен быть дешевым и без side-effects. `handle()` получает только
payload, а не `QueueJob`: это держит доменные обработчики независимыми от
транспортных деталей очереди.

## CompositeQueueJobHandler

```php
use PhpSoftBox\Queue\CompositeQueueJobHandler;
use PhpSoftBox\Queue\QueueJobHandlerInterface;

$handler = new CompositeQueueJobHandler([
    $tenantProvisionPayloadHandler,
    $ozonImportPayloadHandler,
    $telegramSyncPayloadHandler,
]);

assert($handler instanceof QueueJobHandlerInterface);
```

Composite проходит по обработчикам в переданном порядке. Первый handler с
`supports($payload) === true` получает payload, после чего обработка завершается.
Если ни один handler не подошел, бросается `UnsupportedQueuePayloadException`.
Для array-payload с ключом `_job` текст ошибки будет содержать значение `_job`,
иначе используется runtime type payload.

## DI

Пример для контейнера:

```php
use PhpSoftBox\Queue\CompositeQueueJobHandler;
use PhpSoftBox\Queue\QueueJob;
use PhpSoftBox\Queue\QueueJobHandlerInterface;
use Psr\Container\ContainerInterface;

use function DI\factory;

return [
    QueueJobHandlerInterface::class => factory(static function (ContainerInterface $container): QueueJobHandlerInterface {
        return new CompositeQueueJobHandler([
            $container->get(App\Queue\TenantProvisionPayloadHandler::class),
            $container->get(App\Queue\OzonImportPayloadHandler::class),
            $container->get(App\Queue\TelegramSyncPayloadHandler::class),
        ]);
    }),
];
```

В worker-е можно передавать общий handler напрямую:

```php
$worker->run(static function (mixed $payload, QueueJob $job) use ($handler): void {
    $handler->handle($payload, $job);
});
```

Это заменяет большие `switch`/`match` в приложении и позволяет подключать новые
типы задач через DI wiring.
