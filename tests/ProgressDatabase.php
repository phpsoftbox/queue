<?php

declare(strict_types=1);

namespace PhpSoftBox\Queue\Tests;

use PhpSoftBox\Database\Configurator\DatabaseFactory;
use PhpSoftBox\Database\Connection\ConnectionManager;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;
use PhpSoftBox\Queue\DatabaseQueueProgressSchema;
use RuntimeException;

use function bin2hex;
use function getenv;
use function ltrim;
use function random_bytes;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

final class ProgressDatabase
{
    public readonly ConnectionManager $connections;
    public readonly DatabaseQueueProgressSchema $schema;
    public readonly string $dsn;
    private ?string $file = null;

    public function __construct(?DatabaseQueueProgressSchema $schema = null)
    {
        $dsn = getenv('QUEUE_TEST_DSN');
        if ($dsn === false || $dsn === '') {
            $file = tempnam(sys_get_temp_dir(), 'queue_progress_');
            if ($file === false) {
                throw new RuntimeException('Cannot create temporary test database.');
            }
            $this->file = $file;
            $dsn        = 'sqlite:////' . ltrim($file, '/');
        }
        $this->dsn         = $dsn;
        $this->connections = new ConnectionManager(new DatabaseFactory([
            'connections' => [
                'default'  => 'progress',
                'progress' => ['read' => ['dsn' => $dsn], 'write' => ['dsn' => $dsn]],
            ],
        ]));

        $this->schema = $schema ?? new DatabaseQueueProgressSchema(table: 'progress_test_' . bin2hex(random_bytes(6)));
        $s            = $this->schema;
        $this->connections->write('progress')->schema()->create($s->table, static function (TableBlueprint $table) use ($s): void {
            $table->string($s->jobIdColumn, 64)->unique($s->table . '_job_unique')->comment('Идентификатор задания');
            $table->string($s->statusColumn, 32)->default('queued')->comment('Статус');
            $table->integer($s->totalColumn)->nullable()->comment('Всего единиц');
            $table->integer($s->processedColumn)->default(0)->comment('Обработано');
            $table->integer($s->percentColumn)->default(0)->comment('Процент');
            $table->integer($s->stepPercentColumn)->default(1)->comment('Шаг');
            $table->integer($s->attemptColumn)->default(1)->comment('Попытка');
            $table->text($s->errorColumn)->nullable()->comment('Ошибка');
            $table->json($s->metaColumn)->nullable()->comment('Метаданные');
            $table->datetime($s->cancelRequestedDatetimeColumn)->nullable()->comment('Запрос отмены');
            $table->datetime($s->startedDatetimeColumn)->nullable()->comment('Начало');
            $table->datetime($s->finishedDatetimeColumn)->nullable()->comment('Завершение');
            $table->datetime($s->createdDatetimeColumn)->comment('Создание');
            $table->datetime($s->updatedDatetimeColumn)->comment('Обновление');
        });
    }

    public function close(): void
    {
        $this->connections->write('progress')->schema()->dropIfExists($this->schema->table);
        if ($this->file !== null) {
            unlink($this->file);
        }
    }
}
