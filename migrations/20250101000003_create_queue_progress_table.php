<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('queue_progress', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор записи');
            $table->string('job_id', 64)->unique('queue_progress_job_id_unique')->comment('Идентификатор задачи');
            $table->string('status', 32)->default('queued')->comment('Текущий статус прогресса');
            $table->integer('total')->nullable()->comment('Общее количество единиц обработки');
            $table->integer('processed')->default(0)->comment('Количество обработанных единиц');
            $table->integer('percent')->default(0)->comment('Процент выполнения');
            $table->integer('step_percent')->default(1)->comment('Шаг обновления прогресса в процентах');
            $table->integer('attempt')->default(1)->comment('Номер попытки выполнения');
            $table->text('error')->nullable()->comment('Ошибка выполнения (если есть)');
            $table->json('meta')->nullable()->comment('Дополнительные метаданные прогресса');
            $table->datetime('cancel_requested_datetime')->nullable()->comment('Момент запроса отмены');
            $table->datetime('started_datetime')->nullable()->comment('Момент старта выполнения');
            $table->datetime('finished_datetime')->nullable()->comment('Момент завершения выполнения');
            $table->datetime('created_datetime')->useCurrent()->comment('Время создания записи');
            $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate()->comment('Время обновления записи');

            $table->index(['status'], 'queue_progress_status_index');
            $table->index(['updated_datetime'], 'queue_progress_updated_datetime_index');
            $table->index(['cancel_requested_datetime'], 'queue_progress_cancel_requested_datetime_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('queue_progress');
    }
};
