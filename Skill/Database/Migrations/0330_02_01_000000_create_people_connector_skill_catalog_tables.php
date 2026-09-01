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
        'people_connector_skill_categories',
        'people_connector_skill_skills',
        'people_connector_skill_proficiency_scales',
        'people_connector_skill_proficiency_scale_levels',
    ];

    public function up(): void
    {
        $this->createSkillCategories();
        $this->createSkills();
        $this->createProficiencyScales();
        $this->createProficiencyScaleLevels();

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

    private function createSkillCategories(): void
    {
        Schema::create('people_connector_skill_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'pcs_category_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_category_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'code'], 'pcs_category_code_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pcs_category_company_active_idx');
            $table->foreign('tenant_id', 'pcs_category_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $this->addEntityForeignKey($table, 'company_entity_id', 'pcs_category_company_tenant_fk');
        });
    }

    private function createSkills(): void
    {
        Schema::create('people_connector_skill_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('category_id');
            $table->string('code', 80);
            $table->string('name');
            $table->text('definition');
            $table->string('scope', 24)->default('shared');
            $table->unsignedBigInteger('department_entity_id')->nullable();
            $table->string('critical_classification', 24)->nullable();
            $table->text('evidence_guide')->nullable();
            $table->string('default_assessment_method', 40);
            $table->unsignedSmallInteger('default_reassessment_months')->nullable();
            $table->unsignedBigInteger('owner_employee_entity_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'pcs_skill_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_skill_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'code'], 'pcs_skill_code_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pcs_skill_company_active_idx');
            $table->index(['tenant_id', 'category_id'], 'pcs_skill_category_idx');
            $table->foreign('tenant_id', 'pcs_skill_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['category_id', 'tenant_id'], 'pcs_skill_category_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_categories')
                ->restrictOnDelete();
            $this->addEntityForeignKey($table, 'company_entity_id', 'pcs_skill_company_tenant_fk');
            $this->addEntityForeignKey($table, 'department_entity_id', 'pcs_skill_department_tenant_fk');
            $this->addEntityForeignKey($table, 'owner_employee_entity_id', 'pcs_skill_owner_tenant_fk');
        });
    }

    private function createProficiencyScales(): void
    {
        Schema::create('people_connector_skill_proficiency_scales', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('code', 80);
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_scale_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_scale_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'code', 'version'], 'pcs_scale_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status'], 'pcs_scale_status_idx');
            $table->foreign('tenant_id', 'pcs_scale_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $this->addEntityForeignKey($table, 'company_entity_id', 'pcs_scale_company_tenant_fk');
        });
    }

    private function createProficiencyScaleLevels(): void
    {
        Schema::create('people_connector_skill_proficiency_scale_levels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('scale_id');
            $table->unsignedTinyInteger('level');
            $table->string('name', 100);
            $table->text('anchor');
            $table->text('authority');
            $table->timestamps();

            $table->index('tenant_id', 'pcs_level_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_level_id_tenant_uq');
            $table->unique(['tenant_id', 'scale_id', 'level'], 'pcs_level_uq');
            $table->foreign('tenant_id', 'pcs_level_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['scale_id', 'tenant_id'], 'pcs_level_scale_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_proficiency_scales')
                ->restrictOnDelete();
        });
    }

    private function addEntityForeignKey(Blueprint $table, string $column, string $name): void
    {
        $table->foreign([$column, 'tenant_id'], $name)
            ->references(['id', 'tenant_id'])
            ->on('people_connector_connector_workforce_entities')
            ->restrictOnDelete();
    }
};
