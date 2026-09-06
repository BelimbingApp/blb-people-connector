<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One audit row per operator action on a connection (#199): retirement,
 * provider replacement, cutover rehearsal, retention purge. The row carries
 * who, in which tenant, on which connection(s), what, and a redacted
 * before/after summary. It never carries credentials or provider payloads;
 * the writer refuses them (OperatorAuditLog).
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_operator_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->unsignedBigInteger('related_connection_id')->nullable();
            $table->string('operation', 64);
            $table->string('actor_type', 32);
            $table->unsignedBigInteger('actor_id');
            $table->unsignedBigInteger('actor_company_id')->nullable();
            $table->string('review_reference', 191)->nullable();
            $table->json('before_summary');
            $table->json('after_summary');
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'connection_id', 'occurred_at'], 'pc_operator_audit_conn_idx');
            $table->index(['tenant_id', 'related_connection_id'], 'pc_operator_audit_related_idx');
            $table->foreign('tenant_id', 'pc_operator_audit_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_operator_audits');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_operator_audits');
        Schema::dropIfExists('people_connector_connector_operator_audits');
    }
};
