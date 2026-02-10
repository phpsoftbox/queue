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
            $table->integer('priority')->default(0)->comment('Приоритет задачи (чем меньше, тем выше приоритет)');
            $table->datetime('available_datetime')->comment('Время, когда задача становится доступной для выполнения');
            $table->datetime('reserved_datetime')->nullable()->comment('Время, когда задача была зарезервирована для выполнения');
            $table->datetime('created_datetime')->useCurrent()->comment('Время создания записи');
            $table->index(['available_datetime', 'priority'], 'queue_jobs_available_priority_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('queue_jobs');
    }
};
