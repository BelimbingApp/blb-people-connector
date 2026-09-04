<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_skill_assessment_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('assessment_id');
            $table->string('decision', 24);
            $table->unsignedBigInteger('actor_user_id');
            $table->text('notes')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'assessment_id'], 'pcs_decision_assessment_idx');
            $table->index(['tenant_id', 'employee_entity_id', 'skill_id'], 'pcs_decision_subject_idx');
            $table->foreign(['assessment_id', 'tenant_id'], 'pcs_decision_assessment_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_assessments')
                ->cascadeOnDelete();
            $table->foreign('tenant_id', 'pcs_decision_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->createAssessmentWorkflowGuards();
        $this->createDecisionGuards();
        $this->registerTable('people_connector_skill_assessment_decisions');
    }

    public function down(): void
    {
        $this->dropDecisionGuards();
        $this->dropAssessmentWorkflowGuards();
        $this->unregisterTable('people_connector_skill_assessment_decisions');
        Schema::dropIfExists('people_connector_skill_assessment_decisions');
    }

    private function createAssessmentWorkflowGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_assessment_workflow_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF NEW.status = 'draft'
                            AND NEW.hod_verification = 'pending'
                            AND NEW.finalized_at IS NULL
                            AND NEW.finalized_by_user_id IS NULL THEN
                            RETURN NEW;
                        END IF;
                        IF current_setting('blb.skill_assessment_workflow', true) = '1' THEN
                            RETURN NEW;
                        END IF;
                        RAISE EXCEPTION 'non-draft assessment inserts require workflow authority';
                    END IF;

                    IF TG_OP = 'DELETE' THEN
                        IF OLD.status <> 'draft' THEN
                            RAISE EXCEPTION 'non-draft assessment % is historical and cannot be deleted', OLD.id;
                        END IF;
                        RETURN OLD;
                    END IF;

                    IF NEW.status <> 'draft'
                        AND current_setting('blb.skill_assessment_workflow', true) IS DISTINCT FROM '1' THEN
                        RAISE EXCEPTION 'assessment lifecycle updates require workflow authority';
                    END IF;

                    IF NEW.status <> 'draft' AND NEW.assessor_user_id IS NULL THEN
                        RAISE EXCEPTION 'submitted assessment % requires an assessor', NEW.id;
                    END IF;

                    IF OLD.status = 'submitted'
                        AND NEW.status = 'pending_hod_verification'
                        AND NEW.hod_verification = 'pending' THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.status = 'pending_hod_verification'
                        AND NEW.status = 'pending_hod_verification'
                        AND OLD.hod_verification = 'pending'
                        AND NEW.hod_verification IN ('verified', 'rejected')
                        AND NEW.hod_verifier_user_id IS NOT NULL
                        AND NEW.hod_verifier_user_id <> NEW.assessor_user_id
                        AND NEW.hod_verified_at IS NOT NULL THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.status = 'pending_hod_verification'
                        AND NEW.status = 'returned'
                        AND OLD.hod_verification = 'pending'
                        AND NEW.hod_verification = 'rejected'
                        AND NEW.hod_verifier_user_id IS NOT NULL
                        AND NEW.hod_verifier_user_id <> NEW.assessor_user_id
                        AND NEW.hod_verified_at IS NOT NULL
                        AND NEW.hod_decision_notes IS NOT NULL
                        AND btrim(NEW.hod_decision_notes) <> '' THEN
                        RETURN NEW;
                    END IF;

                    IF OLD.status = 'pending_hod_verification'
                        AND OLD.hod_verification = 'verified'
                        AND NEW.status = 'finalized'
                        AND NEW.finalized_at IS NOT NULL
                        AND NEW.finalized_by_user_id IS NOT NULL
                        AND NEW.finalized_by_user_id <> NEW.assessor_user_id THEN
                        RETURN NEW;
                    END IF;

                    IF NEW.status = OLD.status
                        AND NEW.hod_verification = OLD.hod_verification
                        AND NEW.finalized_at IS NOT DISTINCT FROM OLD.finalized_at
                        AND NEW.finalized_by_user_id IS NOT DISTINCT FROM OLD.finalized_by_user_id THEN
                        RETURN NEW;
                    END IF;

                    RAISE EXCEPTION 'invalid skill assessment transition for %: % -> %', OLD.id, OLD.status, NEW.status;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER pcs_assessment_workflow_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_assessments
                    FOR EACH ROW EXECUTE FUNCTION pcs_assessment_workflow_guard();

                CREATE OR REPLACE FUNCTION pcs_assessment_facts_guard() RETURNS trigger AS $$
                BEGIN
                    IF OLD.status <> 'draft' AND NOT (
                        NEW.tenant_id IS NOT DISTINCT FROM OLD.tenant_id
                        AND NEW.company_entity_id IS NOT DISTINCT FROM OLD.company_entity_id
                        AND NEW.employee_entity_id IS NOT DISTINCT FROM OLD.employee_entity_id
                        AND NEW.skill_id IS NOT DISTINCT FROM OLD.skill_id
                        AND NEW.requirement_reference IS NOT DISTINCT FROM OLD.requirement_reference
                        AND NEW.requirement_version IS NOT DISTINCT FROM OLD.requirement_version
                        AND NEW.required_level IS NOT DISTINCT FROM OLD.required_level
                        AND NEW.criticality IS NOT DISTINCT FROM OLD.criticality
                        AND NEW.weight_percent IS NOT DISTINCT FROM OLD.weight_percent
                        AND NEW.mandatory_gate IS NOT DISTINCT FROM OLD.mandatory_gate
                        AND NEW.scale_id IS NOT DISTINCT FROM OLD.scale_id
                        AND NEW.scale_version IS NOT DISTINCT FROM OLD.scale_version
                        AND NEW.assessed_level IS NOT DISTINCT FROM OLD.assessed_level
                        AND NEW.gap IS NOT DISTINCT FROM OLD.gap
                        AND NEW.weighted_gap IS NOT DISTINCT FROM OLD.weighted_gap
                        AND NEW.priority_score IS NOT DISTINCT FROM OLD.priority_score
                        AND NEW.result_band IS NOT DISTINCT FROM OLD.result_band
                        AND NEW.method IS NOT DISTINCT FROM OLD.method
                        AND NEW.cycle IS NOT DISTINCT FROM OLD.cycle
                        AND NEW.evidence IS NOT DISTINCT FROM OLD.evidence
                        AND NEW.notes IS NOT DISTINCT FROM OLD.notes
                        AND NEW.assessor_user_id IS NOT DISTINCT FROM OLD.assessor_user_id
                        AND NEW.assessor_employee_entity_id IS NOT DISTINCT FROM OLD.assessor_employee_entity_id
                        AND NEW.assessed_at IS NOT DISTINCT FROM OLD.assessed_at
                        AND NEW.certificate_number IS NOT DISTINCT FROM OLD.certificate_number
                        AND NEW.valid_until IS NOT DISTINCT FROM OLD.valid_until
                        AND NEW.next_assessment_due IS NOT DISTINCT FROM OLD.next_assessment_due
                        AND NEW.supersedes_assessment_id IS NOT DISTINCT FROM OLD.supersedes_assessment_id
                    ) THEN
                        RAISE EXCEPTION 'submitted assessment facts are immutable; create a correction row';
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pcs_assessment_facts_guard
                    BEFORE UPDATE ON people_connector_skill_assessments
                    FOR EACH ROW EXECUTE FUNCTION pcs_assessment_facts_guard();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pcs_assessment_workflow_delete_guard
                BEFORE DELETE ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN OLD.status <> 'draft'
                BEGIN
                    SELECT RAISE(ABORT, 'non-draft assessment is historical and cannot be deleted');
                END;

                CREATE TRIGGER pcs_assessment_workflow_insert_guard
                BEFORE INSERT ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN NEW.status <> 'draft'
                BEGIN
                    SELECT CASE WHEN pcs_assessment_workflow_authorized() <> 1
                        THEN RAISE(ABORT, 'non-draft assessment inserts require workflow authority') END;
                END;

                CREATE TRIGGER pcs_assessment_workflow_update_guard
                BEFORE UPDATE ON people_connector_skill_assessments
                FOR EACH ROW
                BEGIN
                    SELECT CASE WHEN NEW.status <> 'draft' AND pcs_assessment_workflow_authorized() <> 1
                        THEN RAISE(ABORT, 'assessment lifecycle updates require workflow authority') END;
                    SELECT CASE WHEN NEW.status <> 'draft' AND NEW.assessor_user_id IS NULL
                        THEN RAISE(ABORT, 'submitted assessment requires an assessor') END;
                    SELECT CASE WHEN NOT (
                        (OLD.status = 'submitted' AND NEW.status = 'pending_hod_verification' AND NEW.hod_verification = 'pending')
                        OR (OLD.status = 'pending_hod_verification' AND NEW.status = 'pending_hod_verification'
                            AND OLD.hod_verification = 'pending' AND NEW.hod_verification IN ('verified', 'rejected')
                            AND NEW.hod_verifier_user_id IS NOT NULL AND NEW.hod_verifier_user_id <> NEW.assessor_user_id
                            AND NEW.hod_verified_at IS NOT NULL)
                        OR (OLD.status = 'pending_hod_verification' AND NEW.status = 'returned'
                            AND OLD.hod_verification = 'pending' AND NEW.hod_verification = 'rejected'
                            AND NEW.hod_verifier_user_id IS NOT NULL AND NEW.hod_verifier_user_id <> NEW.assessor_user_id
                            AND NEW.hod_verified_at IS NOT NULL
                            AND NEW.hod_decision_notes IS NOT NULL AND trim(NEW.hod_decision_notes) <> '')
                        OR (OLD.status = 'pending_hod_verification' AND OLD.hod_verification = 'verified'
                            AND NEW.status = 'finalized' AND NEW.finalized_at IS NOT NULL
                            AND NEW.finalized_by_user_id IS NOT NULL AND NEW.finalized_by_user_id <> NEW.assessor_user_id)
                        OR (NEW.status = OLD.status AND NEW.hod_verification = OLD.hod_verification
                            AND NEW.finalized_at IS OLD.finalized_at
                            AND NEW.finalized_by_user_id IS OLD.finalized_by_user_id)
                    ) THEN RAISE(ABORT, 'invalid skill assessment transition') END;
                END;

                CREATE TRIGGER pcs_assessment_facts_guard
                BEFORE UPDATE ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN OLD.status <> 'draft' AND NOT (
                    NEW.tenant_id IS OLD.tenant_id
                    AND NEW.company_entity_id IS OLD.company_entity_id
                    AND NEW.employee_entity_id IS OLD.employee_entity_id
                    AND NEW.skill_id IS OLD.skill_id
                    AND NEW.requirement_reference IS OLD.requirement_reference
                    AND NEW.requirement_version IS OLD.requirement_version
                    AND NEW.required_level IS OLD.required_level
                    AND NEW.criticality IS OLD.criticality
                    AND NEW.weight_percent IS OLD.weight_percent
                    AND NEW.mandatory_gate IS OLD.mandatory_gate
                    AND NEW.scale_id IS OLD.scale_id
                    AND NEW.scale_version IS OLD.scale_version
                    AND NEW.assessed_level IS OLD.assessed_level
                    AND NEW.gap IS OLD.gap
                    AND NEW.weighted_gap IS OLD.weighted_gap
                    AND NEW.priority_score IS OLD.priority_score
                    AND NEW.result_band IS OLD.result_band
                    AND NEW.method IS OLD.method
                    AND NEW.cycle IS OLD.cycle
                    AND NEW.evidence IS OLD.evidence
                    AND NEW.notes IS OLD.notes
                    AND NEW.assessor_user_id IS OLD.assessor_user_id
                    AND NEW.assessor_employee_entity_id IS OLD.assessor_employee_entity_id
                    AND NEW.assessed_at IS OLD.assessed_at
                    AND NEW.certificate_number IS OLD.certificate_number
                    AND NEW.valid_until IS OLD.valid_until
                    AND NEW.next_assessment_due IS OLD.next_assessment_due
                    AND NEW.supersedes_assessment_id IS OLD.supersedes_assessment_id
                )
                BEGIN
                    SELECT RAISE(ABORT, 'submitted assessment facts are immutable; create a correction row');
                END;
                SQL);
        }
    }

    private function dropAssessmentWorkflowGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_workflow_guard ON people_connector_skill_assessments');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_facts_guard ON people_connector_skill_assessments');
            DB::unprepared('DROP FUNCTION IF EXISTS pcs_assessment_workflow_guard()');
            DB::unprepared('DROP FUNCTION IF EXISTS pcs_assessment_facts_guard()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_workflow_delete_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_workflow_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_workflow_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_facts_guard');
        }
    }

    private function createDecisionGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_assessment_decision_append_only() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'INSERT' THEN
                        IF current_setting('blb.skill_assessment_workflow', true) = '1' THEN
                            RETURN NEW;
                        END IF;
                        RAISE EXCEPTION 'assessment decision inserts require workflow authority';
                    END IF;
                    RAISE EXCEPTION 'assessment decisions are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pcs_assessment_decision_guard
                    BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_assessment_decisions
                    FOR EACH ROW EXECUTE FUNCTION pcs_assessment_decision_append_only();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pcs_assessment_decision_update_guard
                BEFORE UPDATE ON people_connector_skill_assessment_decisions
                BEGIN
                    SELECT RAISE(ABORT, 'assessment decisions are append-only');
                END;
                CREATE TRIGGER pcs_assessment_decision_insert_guard
                BEFORE INSERT ON people_connector_skill_assessment_decisions
                BEGIN
                    SELECT CASE WHEN pcs_assessment_workflow_authorized() <> 1
                        THEN RAISE(ABORT, 'assessment decision inserts require workflow authority') END;
                END;
                CREATE TRIGGER pcs_assessment_decision_delete_guard
                BEFORE DELETE ON people_connector_skill_assessment_decisions
                BEGIN
                    SELECT RAISE(ABORT, 'assessment decisions are append-only');
                END;
                SQL);
        }
    }

    private function dropDecisionGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_decision_guard ON people_connector_skill_assessment_decisions');
            DB::unprepared('DROP FUNCTION IF EXISTS pcs_assessment_decision_append_only()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_decision_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_decision_insert_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_decision_delete_guard');
        }
    }
};
