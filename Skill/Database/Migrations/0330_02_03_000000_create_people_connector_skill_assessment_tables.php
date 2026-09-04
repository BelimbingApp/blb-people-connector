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

    /** @var list<string> */
    private array $tables = [
        'people_connector_skill_assessments',
        'people_connector_skill_employee_scores',
    ];

    public function up(): void
    {
        $this->createAssessments();
        $this->createEmployeeScores();
        $this->createFinalizedImmutabilityGuards();

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        $this->dropFinalizedImmutabilityGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    private function createAssessments(): void
    {
        Schema::create('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->string('requirement_reference', 100);
            $table->unsignedInteger('requirement_version');
            $table->unsignedTinyInteger('required_level');
            $table->string('criticality', 24);
            $table->decimal('weight_percent', 5, 2)->nullable();
            $table->boolean('mandatory_gate')->default(false);
            $table->unsignedBigInteger('scale_id')->nullable();
            $table->unsignedInteger('scale_version')->nullable();
            $table->unsignedTinyInteger('assessed_level')->nullable();
            $table->unsignedTinyInteger('gap')->nullable();
            $table->decimal('weighted_gap', 8, 2)->nullable();
            $table->decimal('priority_score', 8, 2)->nullable();
            $table->string('result_band', 24)->nullable();
            $table->string('method', 40);
            $table->string('cycle', 40);
            $table->string('status', 24);
            $table->text('evidence')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('assessor_user_id')->nullable();
            $table->unsignedBigInteger('assessor_employee_entity_id')->nullable();
            $table->timestamp('assessed_at')->nullable();
            $table->string('hod_verification', 24)->default('pending');
            $table->unsignedBigInteger('hod_verifier_user_id')->nullable();
            $table->timestamp('hod_verified_at')->nullable();
            $table->string('certificate_number', 100)->nullable();
            $table->date('valid_until')->nullable();
            $table->date('next_assessment_due')->nullable();
            $table->unsignedBigInteger('supersedes_assessment_id')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('finalized_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_assess_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_assess_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_assess_company_idx');
            $table->index(['tenant_id', 'employee_entity_id', 'skill_id', 'status'], 'pcs_assess_employee_skill_status_idx');
            $table->index(['tenant_id', 'skill_id'], 'pcs_assess_skill_idx');

            $table->foreign('tenant_id', 'pcs_assess_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_assess_employee_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_assess_skill_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_skills')
                ->restrictOnDelete();
            $table->foreign(['supersedes_assessment_id', 'tenant_id'], 'pcs_assess_supersedes_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_assessments')
                ->nullOnDelete();
        });
    }

    private function createEmployeeScores(): void
    {
        Schema::create('people_connector_skill_employee_scores', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('source_assessment_id');
            $table->string('requirement_reference', 100);
            $table->unsignedInteger('requirement_version');
            $table->unsignedTinyInteger('required_level');
            $table->unsignedTinyInteger('current_level');
            $table->unsignedTinyInteger('gap');
            $table->boolean('mandatory_gate')->default(false);
            $table->string('criticality', 24);
            $table->timestamp('assessed_at');
            $table->date('next_assessment_due')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_score_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_score_id_tenant_uq');
            $table->unique(['tenant_id', 'employee_entity_id', 'skill_id'], 'pcs_score_employee_skill_uq');
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_score_company_idx');

            $table->foreign('tenant_id', 'pcs_score_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_score_employee_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_score_skill_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_skills')
                ->restrictOnDelete();
            $table->foreign(['source_assessment_id', 'tenant_id'], 'pcs_score_assessment_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_assessments')
                ->restrictOnDelete();
        });
    }

    private function createFinalizedImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_assessment_finalized_guard() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'DELETE' THEN
                        IF OLD.finalized_at IS NOT NULL THEN
                            RAISE EXCEPTION 'finalized assessment % cannot be deleted', OLD.id;
                        END IF;
                        RETURN OLD;
                    END IF;
                    IF OLD.finalized_at IS NOT NULL THEN
                        RAISE EXCEPTION 'finalized assessment % cannot be modified; supersede with a new row', OLD.id;
                    END IF;
                    RETURN NEW;
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER pcs_assessment_finalized_guard
                    BEFORE UPDATE OR DELETE ON people_connector_skill_assessments
                    FOR EACH ROW EXECUTE FUNCTION pcs_assessment_finalized_guard();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pcs_assessment_finalized_update_guard
                BEFORE UPDATE ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN OLD.finalized_at IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'finalized assessment cannot be modified; supersede with a new row');
                END;

                CREATE TRIGGER pcs_assessment_finalized_delete_guard
                BEFORE DELETE ON people_connector_skill_assessments
                FOR EACH ROW
                WHEN OLD.finalized_at IS NOT NULL
                BEGIN
                    SELECT RAISE(ABORT, 'finalized assessment cannot be deleted');
                END;
                SQL);
        }
    }

    private function dropFinalizedImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_finalized_guard ON people_connector_skill_assessments');
            DB::unprepared('DROP FUNCTION IF EXISTS pcs_assessment_finalized_guard()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_finalized_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_assessment_finalized_delete_guard');
        }
    }
};
