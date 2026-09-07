<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable file exchange ledger (#263; docs/providers/hr2000-file-exchange.md
 * "Immutable exchange record"): one row per file a connection received or
 * produced, written before any parser sees the bytes. The row binds tenant,
 * company, connection, direction, operation, file name and the lowercase
 * SHA-256 of the exact bytes. The unique key is the duplicate rule: the same
 * bytes under one connection and direction are one record, so approval of a
 * file can never carry over to changed bytes.
 *
 * Declares IncubatingSchema because it references the incubating provider
 * connections table: when `migrate --dev` rebuilds that table this one must
 * be dropped and recreated with it.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_file_exchange_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('provider_connection_id');
            $table->string('direction', 16);
            $table->string('operation', 80);
            $table->string('file_name');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('byte_length');
            $table->string('schema_version', 100)->nullable();
            $table->string('evidence_reference', 191)->nullable();
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->string('status', 24)->default('recorded');
            $table->string('status_reason', 191)->nullable();
            $table->timestamps();

            $table->unique(['provider_connection_id', 'direction', 'sha256'], 'pc_file_exchange_key');
            $table->index(['tenant_id', 'recorded_at'], 'pc_file_exchange_recorded_idx');
            $table->index(['tenant_id', 'company_id'], 'pc_file_exchange_company_idx');
            $table->foreign('tenant_id', 'pc_file_exchange_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['provider_connection_id', 'tenant_id'], 'pc_file_exchange_conn_tenant_fk')
                ->references(['id', 'tenant_id'])->on('people_connector_connector_provider_connections')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_file_exchange_records');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_file_exchange_records');
        Schema::dropIfExists('people_connector_connector_file_exchange_records');
    }
};
