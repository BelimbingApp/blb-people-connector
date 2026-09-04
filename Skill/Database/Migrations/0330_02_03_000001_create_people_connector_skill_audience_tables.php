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
        'people_connector_skill_actor_bindings',
        'people_connector_skill_assessor_assignments',
    ];

    public function up(): void
    {
        Schema::create('people_connector_skill_actor_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('platform_user_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('user_entity_id');
            $table->unsignedBigInteger('confirmed_by_user_id');
            $table->string('review_reference', 255);
            $table->timestamp('confirmed_at');
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by_user_id')->nullable();
            $table->string('revocation_reference', 255)->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_actor_binding_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_actor_binding_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'platform_user_id'], 'pcs_actor_binding_user_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'employee_entity_id'], 'pcs_actor_binding_employee_uq');
            $table->index(['tenant_id', 'company_entity_id', 'revoked_at'], 'pcs_actor_binding_active_idx');
            $table->foreign('tenant_id', 'pcs_actor_binding_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'pcs_actor_binding_company_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_actor_binding_employee_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['user_entity_id', 'tenant_id'], 'pcs_actor_binding_user_entity_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
        });

        Schema::create('people_connector_skill_assessor_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('assessor_user_id');
            $table->unsignedBigInteger('employee_entity_id');
            $table->unsignedBigInteger('assigned_by_user_id');
            $table->string('review_reference', 255);
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_assessor_assignment_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_assessor_assignment_id_tenant_uq');
            $table->unique(
                ['tenant_id', 'company_entity_id', 'assessor_user_id', 'employee_entity_id'],
                'pcs_assessor_assignment_subject_uq',
            );
            $table->index(['tenant_id', 'company_entity_id', 'assessor_user_id'], 'pcs_assessor_assignment_lookup_idx');
            $table->foreign('tenant_id', 'pcs_assessor_assignment_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_entity_id', 'tenant_id'], 'pcs_assessor_assignment_company_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['employee_entity_id', 'tenant_id'], 'pcs_assessor_assignment_employee_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
