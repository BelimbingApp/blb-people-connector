<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_retention_purge_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->uuid('run_id');
            $table->unsignedBigInteger('operator_user_id');
            $table->string('table_name', 191);
            $table->unsignedInteger('retention_days')->nullable();
            $table->string('retention_column')->nullable();
            $table->unsignedBigInteger('expected_count');
            $table->unsignedBigInteger('deleted_count');
            $table->timestamp('report_reviewed_at');
            $table->timestamp('executed_at');
            $table->timestamp('created_at');

            $table->index('tenant_id', 'pc_retention_audit_tenant_idx');
            $table->unique(['tenant_id', 'run_id', 'table_name'], 'pc_retention_audit_run_table_uq');
            $table->foreign('tenant_id', 'pc_retention_audit_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_retention_purge_audits');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_retention_purge_audits');
        Schema::dropIfExists('people_connector_connector_retention_purge_audits');
    }
};
