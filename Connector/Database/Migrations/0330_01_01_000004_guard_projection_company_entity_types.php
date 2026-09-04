<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Projection ownership is allowed to change when a provider reports a
     * transfer. This guard deliberately does not decide whether that transfer
     * is authorised; WorkforceProjectionStore and CompanyOwnedQuery do. It
     * proves the narrower invariant the composite foreign key cannot: every
     * owner is a workforce entity whose resource type is company.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuards();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcc_org_company_type_guard_trigger ON people_connector_connector_workforce_organization_units;
                DROP TRIGGER IF EXISTS pcc_position_company_type_guard_trigger ON people_connector_connector_workforce_positions;
                DROP TRIGGER IF EXISTS pcc_employee_company_type_guard_trigger ON people_connector_connector_workforce_employees;
                DROP FUNCTION IF EXISTS pcc_projection_company_type_guard();
            SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS pcc_org_company_type_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_org_company_type_update_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_position_company_type_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_position_company_type_update_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_employee_company_type_insert_guard');
            DB::statement('DROP TRIGGER IF EXISTS pcc_employee_company_type_update_guard');
        }
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pcc_projection_company_type_guard() RETURNS trigger AS $$
            BEGIN
                -- Updating an unrelated fact is the hot path. The trigger is
                -- registered for company_entity_id updates, and this skips the
                -- lookup when a builder happens to set it to its old value.
                IF TG_OP = 'UPDATE' AND NEW.company_entity_id IS NOT DISTINCT FROM OLD.company_entity_id THEN
                    RETURN NEW;
                END IF;

                IF NOT EXISTS (
                    SELECT 1
                    FROM people_connector_connector_workforce_entities
                    WHERE id = NEW.company_entity_id
                        AND tenant_id = NEW.tenant_id
                        AND resource_type = 'company'
                ) THEN
                    RAISE EXCEPTION 'projection owner % must be a company workforce entity in tenant %', NEW.company_entity_id, NEW.tenant_id;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcc_org_company_type_guard_trigger
                BEFORE INSERT OR UPDATE OF company_entity_id ON people_connector_connector_workforce_organization_units
                FOR EACH ROW EXECUTE FUNCTION pcc_projection_company_type_guard();

            CREATE TRIGGER pcc_position_company_type_guard_trigger
                BEFORE INSERT OR UPDATE OF company_entity_id ON people_connector_connector_workforce_positions
                FOR EACH ROW EXECUTE FUNCTION pcc_projection_company_type_guard();

            CREATE TRIGGER pcc_employee_company_type_guard_trigger
                BEFORE INSERT OR UPDATE OF company_entity_id ON people_connector_connector_workforce_employees
                FOR EACH ROW EXECUTE FUNCTION pcc_projection_company_type_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        // Separate INSERT and UPDATE triggers let SQLite skip the cross-table
        // lookup for ordinary projection updates. The statements are literal
        // because the schema-drift verifier reads migration SQL statically.
        DB::statement(
            'CREATE TRIGGER pcc_org_company_type_insert_guard BEFORE INSERT ON people_connector_connector_workforce_organization_units'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_org_company_type_update_guard BEFORE UPDATE OF company_entity_id ON people_connector_connector_workforce_organization_units'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_position_company_type_insert_guard BEFORE INSERT ON people_connector_connector_workforce_positions'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_position_company_type_update_guard BEFORE UPDATE OF company_entity_id ON people_connector_connector_workforce_positions'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_employee_company_type_insert_guard BEFORE INSERT ON people_connector_connector_workforce_employees'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcc_employee_company_type_update_guard BEFORE UPDATE OF company_entity_id ON people_connector_connector_workforce_employees'
            ." WHEN NOT EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities WHERE id = NEW.company_entity_id AND tenant_id = NEW.tenant_id AND resource_type = 'company')"
            ." BEGIN SELECT RAISE(ABORT, 'projection owner must be a company workforce entity'); END",
        );
    }
};
