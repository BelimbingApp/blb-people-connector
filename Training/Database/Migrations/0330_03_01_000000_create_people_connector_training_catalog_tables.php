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
        'people_connector_training_courses',
        'people_connector_training_course_skills',
    ];

    public function up(): void
    {
        $this->createTrainingCourses();
        $this->createTrainingCourseSkills();

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

    private function createTrainingCourses(): void
    {
        Schema::create('people_connector_training_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('code', 80);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('delivery_mode', 24);
            $table->unsignedBigInteger('internal_trainer_employee_entity_id')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'pct_course_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pct_course_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'code'], 'pct_course_code_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pct_course_company_active_idx');
            $table->foreign('tenant_id', 'pct_course_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $this->addEntityForeignKey($table, 'company_entity_id', 'pct_course_company_tenant_fk');
            $this->addEntityForeignKey($table, 'internal_trainer_employee_entity_id', 'pct_course_trainer_tenant_fk');
        });
    }

    private function createTrainingCourseSkills(): void
    {
        Schema::create('people_connector_training_course_skills', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('skill_id');

            $table->index('tenant_id', 'pct_course_skill_tenant_idx');
            $table->unique(['tenant_id', 'course_id', 'skill_id'], 'pct_course_skill_uq');
            $table->foreign('tenant_id', 'pct_course_skill_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['course_id', 'tenant_id'], 'pct_course_skill_course_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_training_courses')
                ->cascadeOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pct_course_skill_skill_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_skills')
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
