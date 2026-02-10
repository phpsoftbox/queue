<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Cli;

use PhpSoftBox\CliApp\Command\ArgumentDefinition;
use PhpSoftBox\CliApp\Command\Command;
use PhpSoftBox\CliApp\Command\CommandRegistryInterface;
use PhpSoftBox\CliApp\Command\OptionDefinition;
use PhpSoftBox\CliApp\Loader\CommandProviderInterface;

final class QueueCommandProvider implements CommandProviderInterface
{
    public function register(CommandRegistryInterface $registry): void
    {
        $registry->register(Command::define(
            name: 'queue:listen',
            description: 'Запустить обработчик очереди',
            signature: [
                new OptionDefinition(
                    name: 'max-jobs',
                    short: 'm',
                    description: 'Количество задач для обработки (0 = без лимита)',
                    required: false,
                    default: 0,
                    type: 'int',
                ),
                new OptionDefinition(
                    name: 'sleep',
                    short: 's',
                    description: 'Пауза при пустой очереди (секунды)',
                    required: false,
                    default: 1,
                    type: 'int',
                ),
            ],
            handler: QueueListenHandler::class,
        ));

        $registry->register(Command::define(
            name: 'queue:run',
            description: 'Выполнить все доступные задания один раз',
            signature: [
                new OptionDefinition(
                    name: 'debug',
                    short: 'd',
                    description: 'Подробный вывод процесса выполнения',
                    flag: true,
                    required: false,
                    default: false,
                    type: 'bool',
                ),
            ],
            handler: QueueRunHandler::class,
        ));

        $registry->register(Command::define(
            name: 'queue:push',
            description: 'Добавить задание в очередь',
            signature: [
                new ArgumentDefinition(
                    name: 'payload',
                    description: 'Payload задания (строка)',
                    required: true,
                    type: 'string',
                ),
                new OptionDefinition(
                    name: 'id',
                    short: 'i',
                    description: 'Идентификатор задания (опционально)',
                    required: false,
                    default: null,
                    type: 'string',
                ),
            ],
            handler: QueuePushHandler::class,
        ));
    }
}
