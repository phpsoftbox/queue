<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('queue_failed_jobs', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор записи');
            $table->string('job_id', 64)->comment('Идентификатор задачи');
            $table->json('payload')->comment('Данные задачи');
            $table->integer('attempts')->default(0)->comment('Количество попыток выполнения');
            $table->text('exception')->comment('Текст ошибки');
            $table->datetime('failed_datetime')->useCurrent()->comment('Время фатального падения');
            $table->index(['failed_datetime'], 'queue_failed_jobs_failed_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('queue_failed_jobs');
    }
};
