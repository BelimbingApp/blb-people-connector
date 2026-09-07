<?php

use App\Base\Database\Concerns\IncubatingSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `active_scope_key` is not a fact an operator states; it is derived from
 * `status` inside ProviderConnection::booted(), and the unique index
 * (tenant_id, active_scope_key) is what makes "one active provider per scope"
 * true. A model save can never break that pair. A query-builder
 * `update(['status' => 'retired'])` could, and left the retired row still
 * answering ProviderConnectionStore::active() — measured on origin/main, the
 * row came back `status = retired, active_scope_key = 'tenant'`.
 *
 * This guard proves the narrower invariant the unique index cannot: the derived
 * column equals the scope key exactly while the connection is active, and is
 * null otherwise. It deliberately does not restate the status vocabulary or the
 * company/scope agreement — those refusals belong to the model, which is the
 * only writer production uses.
 *
 * Declares IncubatingSchema because the guarded table is created by the
 * incubating foundation migration: when `migrate --dev` rebuilds it, these
 * triggers must be dropped and recreated with it instead of leaving a stable
 * ledger row that the preflight refuses to rebuild across.
 */
return new class extends Migration
{
    use IncubatingSchema;

    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuard();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcc_conn_active_scope_guard_trigger ON people_connector_connector_provider_connections;
                DROP FUNCTION IF EXISTS pcc_connection_active_scope_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS pcc_conn_active_scope_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_conn_active_scope_update_guard');
        }
    }

    private function createPostgresGuard(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pcc_connection_active_scope_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.active_scope_key IS DISTINCT FROM (CASE WHEN NEW.status = 'active' THEN NEW.scope_key ELSE NULL END) THEN
                    RAISE EXCEPTION 'provider connection active scope key must be derived from its status';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcc_conn_active_scope_guard_trigger
                BEFORE INSERT OR UPDATE OF status, active_scope_key, scope_key ON people_connector_connector_provider_connections
                FOR EACH ROW EXECUTE FUNCTION pcc_connection_active_scope_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        // Separate INSERT and UPDATE triggers, and a column list on the update,
        // so an ordinary write of an unrelated column skips the check. The
        // statements are literal because the schema-drift verifier reads
        // migration SQL statically.
        DB::statement(
            'CREATE TRIGGER pcc_conn_active_scope_insert_guard BEFORE INSERT ON people_connector_connector_provider_connections'
            ." WHEN NEW.active_scope_key IS NOT (CASE WHEN NEW.status = 'active' THEN NEW.scope_key ELSE NULL END)"
            ." BEGIN SELECT RAISE(ABORT, 'provider connection active scope key must be derived from its status'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_conn_active_scope_update_guard BEFORE UPDATE OF status, active_scope_key, scope_key ON people_connector_connector_provider_connections'
            ." WHEN NEW.active_scope_key IS NOT (CASE WHEN NEW.status = 'active' THEN NEW.scope_key ELSE NULL END)"
            ." BEGIN SELECT RAISE(ABORT, 'provider connection active scope key must be derived from its status'); END",
        );
    }
};
