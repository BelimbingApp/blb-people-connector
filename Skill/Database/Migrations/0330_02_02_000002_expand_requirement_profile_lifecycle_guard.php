<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    private const TRANSITION_PROOFS = 'people_connector_skill_requirement_profile_transition_proofs';

    public function up(): void
    {
        Schema::create(self::TRANSITION_PROOFS, function (Blueprint $table): void {
            $table->uuid('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('operation', 40);
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40)->nullable();
            $table->primary('id', 'pcs_req_transition_proof_pk');
            $table->index('tenant_id', 'pcs_req_transition_proof_tenant_ix');
            $table->unique('profile_id', 'pcs_req_transition_proof_profile_uq');
        });
        $this->registerTable(self::TRANSITION_PROOFS);

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
                        IF NEW.status = 'draft' AND NEW.published_at IS NULL AND NEW.retired_at IS NULL THEN
                            RETURN NEW;
                        END IF;

                        IF ((NEW.status IN ('pending_hod_review', 'pending_hr_review', 'approved')
                                AND NEW.published_at IS NULL AND NEW.retired_at IS NULL)
                            OR (NEW.status = 'published' AND NEW.published_at IS NOT NULL AND NEW.retired_at IS NULL)
                            OR (NEW.status = 'retired' AND NEW.published_at IS NOT NULL AND NEW.retired_at IS NOT NULL))
                            AND EXISTS(
                                SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs
                                WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.id AND subject_id = NEW.id
                                AND operation = 'restore_profile' AND to_status = NEW.status
                            ) THEN
                            DELETE FROM people_connector_skill_requirement_profile_transition_proofs
                            WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.id AND subject_id = NEW.id
                            AND operation = 'restore_profile' AND to_status = NEW.status;
                            RETURN NEW;
                        END IF;

                        RAISE EXCEPTION 'requirement profiles must enter governance as drafts or a verified restore';
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
                        AND EXISTS(
                            SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs
                            WHERE tenant_id = OLD.tenant_id AND profile_id = OLD.id
                            AND operation = 'transition'
                            AND from_status = OLD.status AND to_status = NEW.status
                        )
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
                        DELETE FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = OLD.tenant_id AND profile_id = OLD.id
                        AND operation = 'transition'
                        AND from_status = OLD.status AND to_status = NEW.status;
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
                    restore_operation text;
                BEGIN
                    SELECT status INTO parent_status
                    FROM people_connector_skill_requirement_profiles
                    WHERE id = NEW.profile_id AND tenant_id = NEW.tenant_id
                    FOR UPDATE;

                    restore_operation := CASE TG_TABLE_NAME
                        WHEN 'people_connector_skill_requirement_items' THEN 'restore_item'
                        ELSE 'restore_selector'
                    END;

                    IF parent_status IS DISTINCT FROM 'draft' AND NOT EXISTS(
                        SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id
                        AND subject_id = NEW.id AND operation = restore_operation
                    ) THEN
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

                CREATE OR REPLACE FUNCTION pcs_req_item_guard() RETURNS trigger AS $$
                DECLARE
                    profile_ids bigint[];
                    bad_profile bigint;
                    is_company_merge boolean;
                BEGIN
                    IF TG_OP = 'INSERT' AND EXISTS(
                        SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id
                        AND subject_id = NEW.id AND operation = 'restore_item'
                    ) THEN
                        DELETE FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id
                        AND subject_id = NEW.id AND operation = 'restore_item';
                        RETURN NEW;
                    END IF;
                    IF TG_OP = 'UPDATE'
                        AND NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.profile_id = OLD.profile_id AND NEW.skill_id = OLD.skill_id
                        AND NEW.sequence = OLD.sequence AND NEW.required_level = OLD.required_level
                        AND NEW.criticality = OLD.criticality
                        AND NEW.weight_percent IS NOT DISTINCT FROM OLD.weight_percent
                        AND NEW.mandatory_gate IS NOT DISTINCT FROM OLD.mandatory_gate
                        AND NEW.reassessment_months IS NOT DISTINCT FROM OLD.reassessment_months
                        AND NEW.active IS NOT DISTINCT FROM OLD.active
                        AND NEW.evidence_standard IS NOT DISTINCT FROM OLD.evidence_standard
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                            AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                        ) INTO is_company_merge;
                        IF is_company_merge THEN RETURN NEW; END IF;
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        profile_ids := ARRAY[NEW.profile_id];
                    ELSIF TG_OP = 'DELETE' THEN
                        profile_ids := ARRAY[OLD.profile_id];
                    ELSE
                        profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                    END IF;
                    SELECT id INTO bad_profile FROM people_connector_skill_requirement_profiles
                        WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft' LIMIT 1;
                    IF bad_profile IS NOT NULL THEN
                        RAISE EXCEPTION 'requirement profile % is not draft; its items are immutable', bad_profile;
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE OR REPLACE FUNCTION pcs_req_selector_guard() RETURNS trigger AS $$
                DECLARE
                    profile_ids bigint[];
                    bad_profile bigint;
                    is_merge_carry boolean;
                BEGIN
                    IF TG_OP = 'INSERT' AND EXISTS(
                        SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id
                        AND subject_id = NEW.id AND operation = 'restore_selector'
                    ) THEN
                        DELETE FROM people_connector_skill_requirement_profile_transition_proofs
                        WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id
                        AND subject_id = NEW.id AND operation = 'restore_selector';
                        RETURN NEW;
                    END IF;
                    IF TG_OP = 'UPDATE' THEN
                        IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                            AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                            AND NEW.profile_id = OLD.profile_id AND NEW.selector_type = OLD.selector_type
                            AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                            AND NEW.selector_entity_id IS NOT DISTINCT FROM OLD.selector_entity_id
                            AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                            SELECT EXISTS(
                                SELECT 1 FROM people_connector_connector_workforce_entities
                                WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                                AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                            ) INTO is_merge_carry;
                            IF is_merge_carry THEN RETURN NEW; END IF;
                        END IF;
                        IF NEW.selector_entity_id IS DISTINCT FROM OLD.selector_entity_id
                            AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                            AND NEW.company_entity_id = OLD.company_entity_id
                            AND NEW.profile_id = OLD.profile_id AND NEW.selector_type = OLD.selector_type
                            AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                            AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                            SELECT EXISTS(
                                SELECT 1 FROM people_connector_connector_workforce_entities
                                WHERE tenant_id = OLD.tenant_id AND id = OLD.selector_entity_id
                                AND state = 'merged' AND merged_into_entity_id = NEW.selector_entity_id
                            ) INTO is_merge_carry;
                            IF is_merge_carry THEN RETURN NEW; END IF;
                        END IF;
                    END IF;
                    IF TG_OP = 'INSERT' THEN
                        profile_ids := ARRAY[NEW.profile_id];
                    ELSIF TG_OP = 'DELETE' THEN
                        profile_ids := ARRAY[OLD.profile_id];
                    ELSE
                        profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                    END IF;
                    SELECT id INTO bad_profile FROM people_connector_skill_requirement_profiles
                        WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft' LIMIT 1;
                    IF bad_profile IS NOT NULL THEN
                        RAISE EXCEPTION 'requirement profile % is not draft; its selectors are immutable', bad_profile;
                    END IF;
                    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
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
            .' WHEN NOT ('
            ." (NEW.status = 'draft' AND NEW.published_at IS NULL AND NEW.retired_at IS NULL)"
            .' OR ('
            ." ((NEW.status IN ('pending_hod_review', 'pending_hr_review', 'approved')"
            .' AND NEW.published_at IS NULL AND NEW.retired_at IS NULL)'
            ." OR (NEW.status = 'published' AND NEW.published_at IS NOT NULL AND NEW.retired_at IS NULL)"
            ." OR (NEW.status = 'retired' AND NEW.published_at IS NOT NULL AND NEW.retired_at IS NOT NULL))"
            .' AND EXISTS(SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.id AND subject_id = NEW.id'
            ." AND operation = 'restore_profile' AND to_status = NEW.status)))"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profiles must enter governance as drafts or a verified restore'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_restore_proof_consume');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_restore_proof_consume'
            .' AFTER INSERT ON people_connector_skill_requirement_profiles'
            ." WHEN NEW.status != 'draft'"
            .' BEGIN DELETE FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.id AND subject_id = NEW.id'
            ." AND operation = 'restore_profile' AND to_status = NEW.status; END",
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
            .' AND EXISTS(SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = OLD.tenant_id AND profile_id = OLD.id'
            ." AND operation = 'transition'"
            .' AND from_status = OLD.status AND to_status = NEW.status)'
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
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_transition_proof_consume');
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_transition_proof_consume'
            .' AFTER UPDATE OF status ON people_connector_skill_requirement_profiles'
            .' WHEN OLD.status != NEW.status'
            .' BEGIN DELETE FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = OLD.tenant_id AND profile_id = OLD.id'
            ." AND operation = 'transition'"
            .' AND from_status = OLD.status AND to_status = NEW.status; END',
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_item_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_item_insert_guard BEFORE INSERT ON people_connector_skill_requirement_items'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            .' AND NOT EXISTS(SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id AND subject_id = NEW.id'
            ." AND operation = 'restore_item')"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its children are immutable'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_item_restore_proof_consume');
        DB::statement(
            'CREATE TRIGGER pcs_req_item_restore_proof_consume'
            .' AFTER INSERT ON people_connector_skill_requirement_items'
            .' BEGIN DELETE FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id AND subject_id = NEW.id'
            ." AND operation = 'restore_item'; END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_selector_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_selector_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_requirement_profile_selectors'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            .' AND NOT EXISTS(SELECT 1 FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id AND subject_id = NEW.id'
            ." AND operation = 'restore_selector')"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its children are immutable'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_selector_restore_proof_consume');
        DB::statement(
            'CREATE TRIGGER pcs_req_selector_restore_proof_consume'
            .' AFTER INSERT ON people_connector_skill_requirement_profile_selectors'
            .' BEGIN DELETE FROM people_connector_skill_requirement_profile_transition_proofs'
            .' WHERE tenant_id = NEW.tenant_id AND profile_id = NEW.profile_id AND subject_id = NEW.id'
            ." AND operation = 'restore_selector'; END",
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
        $this->unregisterTable(self::TRANSITION_PROOFS);
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

            $this->restorePostgresChildGuards();
            Schema::dropIfExists(self::TRANSITION_PROOFS);

            return;
        }

        if (DB::connection()->getDriverName() !== 'sqlite') {
            Schema::dropIfExists(self::TRANSITION_PROOFS);

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_insert_guard');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_restore_proof_consume');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_profile_transition_proof_consume');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_item_restore_proof_consume');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_item_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_item_insert_guard BEFORE INSERT ON people_connector_skill_requirement_items'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its children are immutable'); END",
        );
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_selector_restore_proof_consume');
        DB::statement('DROP TRIGGER IF EXISTS pcs_req_selector_insert_guard');
        DB::statement(
            'CREATE TRIGGER pcs_req_selector_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_requirement_profile_selectors'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its children are immutable'); END",
        );
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
        Schema::dropIfExists(self::TRANSITION_PROOFS);
    }

    private function restorePostgresChildGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pcs_req_item_guard() RETURNS trigger AS $$
            DECLARE
                profile_ids bigint[];
                bad_profile bigint;
                is_company_merge boolean;
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                    AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                    AND NEW.profile_id = OLD.profile_id AND NEW.skill_id = OLD.skill_id
                    AND NEW.sequence = OLD.sequence AND NEW.required_level = OLD.required_level
                    AND NEW.criticality = OLD.criticality
                    AND NEW.weight_percent IS NOT DISTINCT FROM OLD.weight_percent
                    AND NEW.mandatory_gate IS NOT DISTINCT FROM OLD.mandatory_gate
                    AND NEW.reassessment_months IS NOT DISTINCT FROM OLD.reassessment_months
                    AND NEW.active IS NOT DISTINCT FROM OLD.active
                    AND NEW.evidence_standard IS NOT DISTINCT FROM OLD.evidence_standard
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                    SELECT EXISTS(
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                        AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                    ) INTO is_company_merge;
                    IF is_company_merge THEN RETURN NEW; END IF;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    profile_ids := ARRAY[NEW.profile_id];
                ELSIF TG_OP = 'DELETE' THEN
                    profile_ids := ARRAY[OLD.profile_id];
                ELSE
                    profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                END IF;
                SELECT id INTO bad_profile FROM people_connector_skill_requirement_profiles
                    WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft' LIMIT 1;
                IF bad_profile IS NOT NULL THEN
                    RAISE EXCEPTION 'requirement profile % is not draft; its items are immutable', bad_profile;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE OR REPLACE FUNCTION pcs_req_selector_guard() RETURNS trigger AS $$
            DECLARE
                profile_ids bigint[];
                bad_profile bigint;
                is_merge_carry boolean;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.profile_id = OLD.profile_id AND NEW.selector_type = OLD.selector_type
                        AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                        AND NEW.selector_entity_id IS NOT DISTINCT FROM OLD.selector_entity_id
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id
                            AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id
                        ) INTO is_merge_carry;
                        IF is_merge_carry THEN RETURN NEW; END IF;
                    END IF;
                    IF NEW.selector_entity_id IS DISTINCT FROM OLD.selector_entity_id
                        AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.company_entity_id = OLD.company_entity_id
                        AND NEW.profile_id = OLD.profile_id AND NEW.selector_type = OLD.selector_type
                        AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id AND id = OLD.selector_entity_id
                            AND state = 'merged' AND merged_into_entity_id = NEW.selector_entity_id
                        ) INTO is_merge_carry;
                        IF is_merge_carry THEN RETURN NEW; END IF;
                    END IF;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    profile_ids := ARRAY[NEW.profile_id];
                ELSIF TG_OP = 'DELETE' THEN
                    profile_ids := ARRAY[OLD.profile_id];
                ELSE
                    profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                END IF;
                SELECT id INTO bad_profile FROM people_connector_skill_requirement_profiles
                    WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft' LIMIT 1;
                IF bad_profile IS NOT NULL THEN
                    RAISE EXCEPTION 'requirement profile % is not draft; its selectors are immutable', bad_profile;
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            SQL);
    }
};
