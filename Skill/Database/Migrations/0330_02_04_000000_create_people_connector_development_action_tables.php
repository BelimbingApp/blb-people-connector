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
        'people_connector_skill_development_actions',
        'people_connector_skill_development_action_events',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_development_actions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->uuid('action_key');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedBigInteger('source_assessment_id')->nullable();
            $table->unsignedBigInteger('post_assessment_id')->nullable();
            $table->string('training_course_code', 100)->nullable();
            $table->string('employee_name_snapshot');
            $table->string('department_snapshot')->nullable();
            $table->string('position_snapshot')->nullable();
            $table->unsignedTinyInteger('starting_level');
            $table->unsignedTinyInteger('target_level');
            $table->unsignedTinyInteger('gap_at_start');
            $table->string('criticality', 24);
            $table->boolean('mandatory_gate')->default(false);
            $table->unsignedInteger('priority_score');
            $table->string('priority_explanation');
            $table->text('manual_reason')->nullable();
            $table->string('action_type', 40);
            $table->text('objective');
            $table->text('intervention');
            $table->text('expected_evidence');
            $table->unsignedBigInteger('owner_employee_entity_id');
            $table->unsignedBigInteger('hr_coordinator_employee_entity_id');
            $table->unsignedBigInteger('trainer_employee_entity_id')->nullable();
            $table->string('trainer_provider_name')->nullable();
            $table->date('start_date');
            $table->date('due_date');
            $table->string('status', 32);
            $table->string('closure_status', 32);
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by_user_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_evidence')->nullable();
            $table->date('reassessment_due')->nullable();
            $table->unsignedTinyInteger('post_level')->nullable();
            $table->smallInteger('improvement')->nullable();
            $table->text('next_steps')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_dev_action_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_dev_action_id_tenant_uq');
            $table->unique(['tenant_id', 'action_key'], 'pcs_dev_action_key_uq');
            $table->unique(['tenant_id', 'source_assessment_id'], 'pcs_dev_action_source_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status', 'due_date'], 'pcs_dev_action_ops_idx');
            $table->index(['tenant_id', 'employee_entity_id', 'skill_id'], 'pcs_dev_action_employee_skill_idx');
            $table->index(['tenant_id', 'mandatory_gate', 'priority_score'], 'pcs_dev_action_priority_idx');

            $table->foreign('tenant_id', 'pcs_dev_action_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_dev_action_employee_fk')->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
            $table->foreign(['owner_employee_entity_id', 'tenant_id'], 'pcs_dev_action_owner_fk')->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
            $table->foreign(['hr_coordinator_employee_entity_id', 'tenant_id'], 'pcs_dev_action_hr_fk')->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
            $table->foreign(['trainer_employee_entity_id', 'tenant_id'], 'pcs_dev_action_trainer_fk')->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_dev_action_skill_fk')->references(['id', 'tenant_id'])->on('people_connector_skill_skills')->restrictOnDelete();
            $table->foreign(['source_assessment_id', 'tenant_id'], 'pcs_dev_action_source_fk')->references(['id', 'tenant_id'])->on('people_connector_skill_assessments')->restrictOnDelete();
            $table->foreign(['post_assessment_id', 'tenant_id'], 'pcs_dev_action_post_fk')->references(['id', 'tenant_id'])->on('people_connector_skill_assessments')->restrictOnDelete();
        });

        Schema::create('people_connector_skill_development_action_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('development_action_id');
            $table->string('event_type', 40);
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('comment')->nullable();
            $table->text('evidence')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_employee_entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->index('tenant_id', 'pcs_dev_event_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_dev_event_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id', 'development_action_id', 'occurred_at'], 'pcs_dev_event_action_idx');
            $table->foreign('tenant_id', 'pcs_dev_event_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['development_action_id', 'tenant_id'], 'pcs_dev_event_action_fk')->references(['id', 'tenant_id'])->on('people_connector_skill_development_actions')->restrictOnDelete();
            $table->foreign(['actor_employee_entity_id', 'tenant_id'], 'pcs_dev_event_actor_fk')->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
        });

        $this->createAuditImmutabilityGuards();

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        $this->dropAuditImmutabilityGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    private function createAuditImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION pcs_dev_action_event_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'development action audit events are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pcs_dev_action_event_immutable
                    BEFORE UPDATE OR DELETE ON people_connector_skill_development_action_events
                    FOR EACH ROW EXECUTE FUNCTION pcs_dev_action_event_immutable();
                SQL);
        } elseif ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pcs_dev_action_event_update_guard
                BEFORE UPDATE ON people_connector_skill_development_action_events
                BEGIN SELECT RAISE(ABORT, 'development action audit events are append-only'); END;
                CREATE TRIGGER pcs_dev_action_event_delete_guard
                BEFORE DELETE ON people_connector_skill_development_action_events
                BEGIN SELECT RAISE(ABORT, 'development action audit events are append-only'); END;
                SQL);
        }
    }

    private function dropAuditImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_dev_action_event_immutable ON people_connector_skill_development_action_events');
            DB::unprepared('DROP FUNCTION IF EXISTS pcs_dev_action_event_immutable()');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_dev_action_event_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pcs_dev_action_event_delete_guard');
        }
    }
};
