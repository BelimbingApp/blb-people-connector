<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS pcs_req_profile_current_uq'
            .' ON people_connector_skill_requirement_profiles (tenant_id, company_entity_id, code)'
            ." WHERE status = 'published'",
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS pcs_req_profile_open_uq'
            .' ON people_connector_skill_requirement_profiles (tenant_id, company_entity_id, code)'
            ." WHERE status IN ('draft', 'pending_hod_review', 'pending_hr_review', 'approved')",
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_req_profile_guard() RETURNS trigger AS $$
                DECLARE
                    is_company_merge boolean;
                    valid_transition boolean;
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.status <> 'draft' OR NEW.published_at IS NOT NULL OR NEW.retired_at IS NOT NULL THEN
                            RAISE EXCEPTION 'requirement profiles must enter governance as drafts';
                        END IF;
                        RETURN NEW;
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        IF OLD.status <> 'draft' OR OLD.published_at IS NOT NULL THEN
                            RAISE EXCEPTION 'requirement profile % entered governance and cannot be deleted', OLD.id;
                        END IF;
                        RETURN OLD;
                    END IF;

                    valid_transition := (OLD.status = 'draft' AND NEW.status = 'pending_hod_review')
                        OR (OLD.status = 'pending_hod_review' AND NEW.status IN ('draft', 'pending_hr_review'))
                        OR (OLD.status = 'pending_hr_review' AND NEW.status IN ('draft', 'approved'))
                        OR (OLD.status = 'approved' AND NEW.status IN ('draft', 'published'))
                        OR (OLD.status = 'published' AND NEW.status = 'retired');

                    IF valid_transition
                        AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.company_entity_id = OLD.company_entity_id
                        AND NEW.code = OLD.code
                        AND NEW.name = OLD.name
                        AND NEW.version = OLD.version
                        AND NEW.effective_date IS NOT DISTINCT FROM OLD.effective_date
                        AND NEW.owner_employee_entity_id IS NOT DISTINCT FROM OLD.owner_employee_entity_id
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
                        AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL)
                            OR (NEW.status <> 'published' AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at))
                        AND ((NEW.status = 'retired' AND NEW.retired_at IS NOT NULL)
                            OR (NEW.status <> 'retired' AND NEW.retired_at IS NOT DISTINCT FROM OLD.retired_at)) THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.status = 'draft' AND NEW.status = 'draft' THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.code = OLD.code AND NEW.name = OLD.name AND NEW.version = OLD.version
                        AND NEW.status = OLD.status
                        AND NEW.effective_date IS NOT DISTINCT FROM OLD.effective_date
                        AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at
                        AND NEW.retired_at IS NOT DISTINCT FROM OLD.retired_at
                        AND NEW.owner_employee_entity_id IS NOT DISTINCT FROM OLD.owner_employee_entity_id
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                            AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                        ) INTO is_company_merge;
                        IF is_company_merge THEN RETURN NEW; END IF;
                    END IF;

                    RAISE EXCEPTION 'requirement profile % is % and immutable or transition is invalid', OLD.id, OLD.status;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS pcs_req_profile_guard_trigger ON people_connector_skill_requirement_profiles;
                CREATE TRIGGER pcs_req_profile_guard_trigger
                    BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_requirement_profiles
                    FOR EACH ROW EXECUTE FUNCTION pcs_req_profile_guard();

                CREATE OR REPLACE FUNCTION pcs_req_child_insert_guard() RETURNS trigger AS $$
                DECLARE
                    parent_status text;
                BEGIN
                    SELECT status INTO parent_status
                    FROM people_connector_skill_requirement_profiles
                    WHERE id = NEW.profile_id AND tenant_id = NEW.tenant_id
                    FOR UPDATE;

                    IF parent_status IS DISTINCT FROM 'draft' THEN
                        RAISE EXCEPTION 'requirement profile % is not draft; children are immutable', NEW.profile_id;
                    END IF;

                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS pcs_req_item_00_parent_guard ON people_connector_skill_requirement_items;
                CREATE TRIGGER pcs_req_item_00_parent_guard
                    BEFORE INSERT ON people_connector_skill_requirement_items
                    FOR EACH ROW EXECUTE FUNCTION pcs_req_child_insert_guard();

                DROP TRIGGER IF EXISTS pcs_req_selector_00_parent_guard ON people_connector_skill_requirement_profile_selectors;
                CREATE TRIGGER pcs_req_selector_00_parent_guard
                    BEFORE INSERT ON people_connector_skill_requirement_profile_selectors
                    FOR EACH ROW EXECUTE FUNCTION pcs_req_child_insert_guard();
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_insert_guard BEFORE INSERT ON people_connector_skill_requirement_profiles'
            ." WHEN NEW.status != 'draft' OR NEW.published_at IS NOT NULL OR NEW.retired_at IS NOT NULL"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profiles must enter governance as drafts'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_update_guard BEFORE UPDATE ON people_connector_skill_requirement_profiles'
            .' WHEN NOT ('
            ." (OLD.status = 'draft' AND NEW.status = 'draft')"
            .' OR ('
            ." ((OLD.status = 'draft' AND NEW.status = 'pending_hod_review')"
            ." OR (OLD.status = 'pending_hod_review' AND NEW.status IN ('draft', 'pending_hr_review'))"
            ." OR (OLD.status = 'pending_hr_review' AND NEW.status IN ('draft', 'approved'))"
            ." OR (OLD.status = 'approved' AND NEW.status IN ('draft', 'published'))"
            ." OR (OLD.status = 'published' AND NEW.status = 'retired'))"
            .' AND NEW.tenant_id IS OLD.tenant_id AND NEW.company_entity_id IS OLD.company_entity_id'
            .' AND NEW.code IS OLD.code AND NEW.name IS OLD.name AND NEW.version IS OLD.version'
            .' AND NEW.effective_date IS OLD.effective_date AND NEW.owner_employee_entity_id IS OLD.owner_employee_entity_id'
            .' AND NEW.created_at IS OLD.created_at'
            ." AND ((NEW.status = 'published' AND NEW.published_at IS NOT NULL)"
            ." OR (NEW.status != 'published' AND NEW.published_at IS OLD.published_at))"
            ." AND ((NEW.status = 'retired' AND NEW.retired_at IS NOT NULL)"
            ." OR (NEW.status != 'retired' AND NEW.retired_at IS OLD.retired_at)))"
            .' OR (NEW.company_entity_id IS NOT OLD.company_entity_id'
            .' AND NEW.id IS OLD.id AND NEW.tenant_id IS OLD.tenant_id AND NEW.code IS OLD.code AND NEW.name IS OLD.name'
            .' AND NEW.version IS OLD.version AND NEW.status IS OLD.status'
            .' AND NEW.effective_date IS OLD.effective_date AND NEW.published_at IS OLD.published_at'
            .' AND NEW.retired_at IS OLD.retired_at AND NEW.owner_employee_entity_id IS OLD.owner_employee_entity_id'
            .' AND NEW.created_at IS OLD.created_at'
            .' AND EXISTS(SELECT 1 FROM people_connector_connector_workforce_entities'
            ." WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id AND state = 'merged'"
            .' AND merged_into_entity_id = NEW.company_entity_id))'
            .')'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is immutable or transition is invalid'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_delete_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_delete_guard BEFORE DELETE ON people_connector_skill_requirement_profiles'
            ." WHEN OLD.status != 'draft' OR OLD.published_at IS NOT NULL"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile entered governance and cannot be deleted'); END",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS pcs_req_profile_current_uq');
        DB::statement('DROP INDEX IF EXISTS pcs_req_profile_open_uq');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcs_req_item_00_parent_guard ON people_connector_skill_requirement_items;
                DROP TRIGGER IF EXISTS pcs_req_selector_00_parent_guard ON people_connector_skill_requirement_profile_selectors;
                DROP FUNCTION IF EXISTS pcs_req_child_insert_guard();

                CREATE OR REPLACE FUNCTION pcs_req_profile_guard() RETURNS trigger AS $$
                DECLARE
                    is_company_merge boolean;
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.published_at IS NOT NULL THEN
                            RAISE EXCEPTION 'requirement profile % has been published and cannot be deleted', OLD.id;
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF OLD.status = 'draft' THEN
                        RETURN NEW;
                    END IF;
                    IF OLD.status = 'published' AND NEW.status = 'retired'
                        AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.company_entity_id = OLD.company_entity_id
                        AND NEW.code = OLD.code
                        AND NEW.name = OLD.name
                        AND NEW.version = OLD.version
                        AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at THEN
                        RETURN NEW;
                    END IF;
                    IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.code = OLD.code AND NEW.name = OLD.name AND NEW.version = OLD.version
                        AND NEW.status = OLD.status
                        AND NEW.effective_date IS NOT DISTINCT FROM OLD.effective_date
                        AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at
                        AND NEW.retired_at IS NOT DISTINCT FROM OLD.retired_at
                        AND NEW.owner_employee_entity_id IS NOT DISTINCT FROM OLD.owner_employee_entity_id
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                            AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                        ) INTO is_company_merge;
                        IF is_company_merge THEN RETURN NEW; END IF;
                    END IF;
                    RAISE EXCEPTION 'requirement profile % is % and immutable; draft a new version instead', OLD.id, OLD.status;
                END;
                $$ LANGUAGE plpgsql;

                DROP TRIGGER IF EXISTS pcs_req_profile_guard_trigger ON people_connector_skill_requirement_profiles;
                CREATE TRIGGER pcs_req_profile_guard_trigger
                    BEFORE UPDATE OR DELETE ON people_connector_skill_requirement_profiles
                    FOR EACH ROW EXECUTE FUNCTION pcs_req_profile_guard();
                SQL);

            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_update_guard BEFORE UPDATE ON people_connector_skill_requirement_profiles'
            ." WHEN NOT (OLD.status = 'draft' OR (OLD.status = 'published' AND NEW.status = 'retired'"
            .' AND NEW.tenant_id = OLD.tenant_id AND NEW.company_entity_id = OLD.company_entity_id'
            .' AND NEW.code = OLD.code AND NEW.name = OLD.name AND NEW.version = OLD.version'
            .' AND NEW.published_at IS OLD.published_at)'
            .' OR (NEW.company_entity_id != OLD.company_entity_id'
            .' AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id AND NEW.code = OLD.code AND NEW.name = OLD.name'
            .' AND NEW.version = OLD.version AND NEW.status = OLD.status'
            .' AND NEW.effective_date IS OLD.effective_date AND NEW.published_at IS OLD.published_at'
            .' AND NEW.retired_at IS OLD.retired_at AND NEW.owner_employee_entity_id IS OLD.owner_employee_entity_id'
            .' AND NEW.created_at IS OLD.created_at'
            .' AND EXISTS(SELECT 1 FROM people_connector_connector_workforce_entities'
            ." WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id AND state = 'merged'"
            .' AND merged_into_entity_id = NEW.company_entity_id)))'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is published and immutable; draft a new version instead'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_delete_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_delete_guard BEFORE DELETE ON people_connector_skill_requirement_profiles'
            .' WHEN OLD.published_at IS NOT NULL'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile has been published and cannot be deleted'); END",
        );
    }
};
