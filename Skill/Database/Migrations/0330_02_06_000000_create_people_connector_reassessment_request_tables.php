<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    /** @var list<string> */
    private array $tables = [
        'people_connector_skill_reassessment_requests',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_reassessment_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedTinyInteger('target_level');
            $table->string('cycle', 40);
            $table->string('source', 40);
            $table->string('status', 24);
            $table->date('due_date');
            $table->unsignedBigInteger('assigned_evaluator_user_id')->nullable();
            $table->text('required_evidence')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('source_development_action_id')->nullable();
            $table->unsignedBigInteger('source_training_event_id')->nullable();
            $table->unsignedBigInteger('source_assessment_id')->nullable();
            $table->unsignedBigInteger('fulfilled_assessment_id')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_reassess_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_reassess_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status', 'due_date'], 'pcs_reassess_ops_idx');
            $table->index(['tenant_id', 'employee_entity_id', 'skill_id', 'status'], 'pcs_reassess_employee_skill_idx');
            $table->unique(['tenant_id', 'source_development_action_id'], 'pcs_reassess_source_action_uq');

            $table->foreign('tenant_id', 'pcs_reassess_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_reassess_employee_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_reassess_skill_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_skills')
                ->restrictOnDelete();
            $table->foreign(['source_development_action_id', 'tenant_id'], 'pcs_reassess_action_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_development_actions')
                ->nullOnDelete();
            $table->foreign(['fulfilled_assessment_id', 'tenant_id'], 'pcs_reassess_fulfilled_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_assessments')
                ->nullOnDelete();
            $table->foreign(['source_assessment_id', 'tenant_id'], 'pcs_reassess_source_assess_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_assessments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('people_connector_skill_reassessment_requests');
    }
};
