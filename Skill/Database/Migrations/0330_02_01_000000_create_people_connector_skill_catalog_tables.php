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

        $this->createImmutabilityGuards();

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        $this->dropImmutabilityGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }

    /**
     * Database-level enforcement of the published-scale and stable-skill-code
     * invariants (precedent: 0200_01_07_001007_scope_custom_authz_roles).
     * Model events alone do not fire for builder or raw writes; #10 requires
     * that a published scale "cannot be silently mutated", so the database
     * itself refuses: any UPDATE/DELETE of a non-draft scale (except the pure
     * published → retired transition), any level write under a non-draft
     * scale, and any change to a skill's code.
     */
    private function createImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuards();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pcs_scale_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.published_at IS NOT NULL THEN
                        RAISE EXCEPTION 'proficiency scale % has been published and cannot be deleted', OLD.id;
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
                RAISE EXCEPTION 'proficiency scale % is % and immutable; draft a new version instead', OLD.id, OLD.status;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_scale_guard_trigger
                BEFORE UPDATE OR DELETE ON people_connector_skill_proficiency_scales
                FOR EACH ROW EXECUTE FUNCTION pcs_scale_guard();

            CREATE OR REPLACE FUNCTION pcs_level_guard() RETURNS trigger AS $$
            DECLARE
                scale_ids bigint[];
                bad_scale bigint;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    scale_ids := ARRAY[NEW.scale_id];
                ELSIF TG_OP = 'DELETE' THEN
                    scale_ids := ARRAY[OLD.scale_id];
                ELSE
                    scale_ids := ARRAY[OLD.scale_id, NEW.scale_id];
                END IF;
                SELECT id INTO bad_scale
                    FROM people_connector_skill_proficiency_scales
                    WHERE id = ANY(scale_ids) AND status IS DISTINCT FROM 'draft'
                    LIMIT 1;
                IF bad_scale IS NOT NULL THEN
                    RAISE EXCEPTION 'proficiency scale % is not draft; its levels are immutable', bad_scale;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_level_guard_trigger
                BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_proficiency_scale_levels
                FOR EACH ROW EXECUTE FUNCTION pcs_level_guard();

            CREATE OR REPLACE FUNCTION pcs_skill_code_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'skill code % is stable and cannot be changed', OLD.code;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_skill_code_guard_trigger
                BEFORE UPDATE ON people_connector_skill_skills
                FOR EACH ROW EXECUTE FUNCTION pcs_skill_code_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        DB::statement(
            'CREATE TRIGGER pcs_scale_update_guard BEFORE UPDATE ON people_connector_skill_proficiency_scales'
            ." WHEN NOT (OLD.status = 'draft' OR (OLD.status = 'published' AND NEW.status = 'retired'"
            .' AND NEW.tenant_id = OLD.tenant_id AND NEW.company_entity_id = OLD.company_entity_id'
            .' AND NEW.code = OLD.code AND NEW.name = OLD.name AND NEW.version = OLD.version'
            .' AND NEW.published_at IS OLD.published_at))'
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is published and immutable; draft a new version instead'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_scale_delete_guard BEFORE DELETE ON people_connector_skill_proficiency_scales'
            .' WHEN OLD.published_at IS NOT NULL'
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale has been published and cannot be deleted'); END",
        );

        $notDraft = static fn (string $row): string => "(SELECT status FROM people_connector_skill_proficiency_scales WHERE id = $row.scale_id) != 'draft'";
        foreach ([
            'INSERT' => $notDraft('NEW'),
            'UPDATE' => $notDraft('NEW').' OR '.$notDraft('OLD'),
            'DELETE' => $notDraft('OLD'),
        ] as $operation => $condition) {
            DB::statement(
                'CREATE TRIGGER pcs_level_'.strtolower($operation).'_guard'
                ." BEFORE $operation ON people_connector_skill_proficiency_scale_levels"
                ." WHEN $condition"
                ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is not draft; its levels are immutable'); END",
            );
        }

        DB::statement(
            'CREATE TRIGGER pcs_skill_code_guard BEFORE UPDATE ON people_connector_skill_skills'
            .' WHEN NEW.code != OLD.code'
            ." BEGIN SELECT RAISE(ABORT, 'skill code is stable and cannot be changed'); END",
        );
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcs_scale_guard_trigger ON people_connector_skill_proficiency_scales;
                DROP TRIGGER IF EXISTS pcs_level_guard_trigger ON people_connector_skill_proficiency_scale_levels;
                DROP TRIGGER IF EXISTS pcs_skill_code_guard_trigger ON people_connector_skill_skills;
                DROP FUNCTION IF EXISTS pcs_scale_guard();
                DROP FUNCTION IF EXISTS pcs_level_guard();
                DROP FUNCTION IF EXISTS pcs_skill_code_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'pcs_scale_update_guard', 'pcs_scale_delete_guard',
                'pcs_level_insert_guard', 'pcs_level_update_guard', 'pcs_level_delete_guard',
                'pcs_skill_code_guard',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
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
