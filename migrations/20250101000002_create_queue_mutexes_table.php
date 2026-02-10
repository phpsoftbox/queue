<?php

declare(strict_types=1);

use PhpSoftBox\Database\Migrations\AbstractMigration;
use PhpSoftBox\Database\SchemaBuilder\TableBlueprint;

return new class extends AbstractMigration
{
    public function up(): void
    {
        $this->schema()->create('queue_mutexes', function (TableBlueprint $table): void {
            $table->id()->comment('Идентификатор записи');
            $table->string('mutex_key', 255)->unique('queue_mutexes_mutex_key_unique')->comment('Ключ мьютекса');
            $table->string('owner_job_id', 64)->comment('Идентификатор job-владельца мьютекса');
            $table->datetime('expires_datetime')->comment('Срок действия мьютекса');
            $table->datetime('created_datetime')->useCurrent()->comment('Время создания записи');
            $table->datetime('updated_datetime')->useCurrent()->useCurrentOnUpdate()->comment('Время обновления записи');

            $table->index(['expires_datetime'], 'queue_mutexes_expires_datetime_index');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('queue_mutexes');
    }
};
