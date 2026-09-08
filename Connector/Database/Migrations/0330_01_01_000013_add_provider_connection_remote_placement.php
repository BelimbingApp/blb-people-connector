<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a connection's provider actually runs (#310, plan 1010-h).
 *
 * `ProviderConnectionMode` has declared RemoteHttp since the enum landed, but
 * nothing stored a placement, so every connection was treated as in-process and
 * a separately hosted People installation was reported healthy by the local
 * adapter's own process while the remote host was down. The placement has to be
 * a column: it decides which health path runs, and a decision that lives only in
 * configuration cannot be scoped per connection or per tenant.
 *
 * `remote_credential_id` is nullable and deliberately not a foreign key. The
 * credentials table is keyed (id, tenant_id) for tenant isolation, and a plain
 * single-column reference would let a sibling tenant's credential be named here
 * and only fail later, at use. ProviderConnectionStore::configure() refuses a
 * credential belonging to another connection or tenant at write time, which is
 * where the operator can still be told why.
 *
 * Declares IncubatingSchema because it alters people_connector_connector_provider_connections,
 * which an incubating migration creates. A stable forward onto an incubating
 * table is what IncubatingSchemaConflictException refuses (#252): rebuilding the
 * create alone would drop these columns while the ledger still claimed they were
 * applied. Joining the replay chain means they are dropped and recreated with
 * the table they belong to.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        Schema::table('people_connector_connector_provider_connections', function (Blueprint $table): void {
            $table->string('mode', 24)->default('in_process')->after('provider_id');
            $table->string('remote_base_url', 500)->nullable()->after('mode');
            $table->unsignedBigInteger('remote_credential_id')->nullable()->after('remote_base_url');
            $table->index(['tenant_id', 'mode'], 'pc_conn_tenant_mode_idx');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_connector_provider_connections', function (Blueprint $table): void {
            $table->dropIndex('pc_conn_tenant_mode_idx');
            $table->dropColumn(['mode', 'remote_base_url', 'remote_credential_id']);
        });
    }
};
