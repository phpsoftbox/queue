<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('queue_jobs', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор записи');
            $table->string('job_id', 64)->unique('queue_jobs_job_id_unique')->comment('Идентификатор задачи');
            $table->json('payload')->comment('Данные задачи');
            $table->integer('attempts')->default(0)->comment('Количество попыток выполнения');
            $table->integer('priority')->default(0)->comment('Приоритет задачи (чем больше, тем выше приоритет)');
            $table->datetime('available_datetime')->comment('Время, когда задача становится доступной для выполнения');
            $table->datetime('reserved_datetime')->nullable()->comment('Время повторной видимости задачи (visibility timeout)');
            $table->string('mutex_key', 255)->nullable()->comment('Ключ мьютекса для исключения параллельного выполнения задач');
            $table->integer('mutex_ttl_seconds')->nullable()->comment('TTL мьютекса в секундах');
            $table->boolean('is_cancellable')->default(0)->comment('Флаг поддержки отмены задачи');
            $table->datetime('created_datetime')->useCurrent()->comment('Время создания записи');
            $table->index(['available_datetime', 'priority'], 'queue_jobs_available_priority_index');
            $table->index(['reserved_datetime'], 'queue_jobs_reserved_datetime_index');
            $table->index(['mutex_key'], 'queue_jobs_mutex_key_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('queue_jobs');
    }
};
