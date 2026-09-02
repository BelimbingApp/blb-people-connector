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
                -- A company merge carries a published scale to the survivor:
                -- only the owner changes, and only from an entity already
                -- marked merged into the new owner. Content and lifecycle
                -- stay immutable.
                IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                    AND NEW.status = OLD.status
                    AND NEW.tenant_id = OLD.tenant_id
                    AND NEW.code = OLD.code
                    AND NEW.name = OLD.name
                    AND NEW.version = OLD.version
                    AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at
                    AND NEW.retired_at IS NOT DISTINCT FROM OLD.retired_at
                    AND EXISTS (
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE id = OLD.company_entity_id
                            AND tenant_id = OLD.tenant_id
                            AND state = 'merged'
                            AND merged_into_entity_id = NEW.company_entity_id
                    ) THEN
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

            CREATE OR REPLACE FUNCTION pcs_company_owner_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                    AND NOT EXISTS (
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE id = OLD.company_entity_id
                            AND tenant_id = OLD.tenant_id
                            AND state = 'merged'
                            AND merged_into_entity_id = NEW.company_entity_id
                    ) THEN
                    RAISE EXCEPTION 'catalog row % belongs to company entity % and cannot move to another company', OLD.id, OLD.company_entity_id;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_category_company_owner_guard_trigger
                BEFORE UPDATE ON people_connector_skill_categories
                FOR EACH ROW EXECUTE FUNCTION pcs_company_owner_guard();

            CREATE TRIGGER pcs_skill_company_owner_guard_trigger
                BEFORE UPDATE ON people_connector_skill_skills
                FOR EACH ROW EXECUTE FUNCTION pcs_company_owner_guard();

            CREATE TRIGGER pcs_scale_company_owner_guard_trigger
                BEFORE UPDATE ON people_connector_skill_proficiency_scales
                FOR EACH ROW EXECUTE FUNCTION pcs_company_owner_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        // The third arm mirrors the plpgsql function: a company merge may
        // change the owner of a non-draft scale, and nothing else, and only
        // from an entity already marked merged into the new owner.
        DB::statement(
            'CREATE TRIGGER pcs_scale_update_guard BEFORE UPDATE ON people_connector_skill_proficiency_scales'
            ." WHEN NOT (OLD.status = 'draft' OR (OLD.status = 'published' AND NEW.status = 'retired'"
            .' AND NEW.tenant_id = OLD.tenant_id AND NEW.company_entity_id = OLD.company_entity_id'
            .' AND NEW.code = OLD.code AND NEW.name = OLD.name AND NEW.version = OLD.version'
            .' AND NEW.published_at IS OLD.published_at)'
            .' OR (NEW.company_entity_id != OLD.company_entity_id AND NEW.status = OLD.status'
            .' AND NEW.tenant_id = OLD.tenant_id AND NEW.code = OLD.code AND NEW.name = OLD.name'
            .' AND NEW.version = OLD.version AND NEW.published_at IS OLD.published_at AND NEW.retired_at IS OLD.retired_at'
            .' AND EXISTS (SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = OLD.company_entity_id AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id)))"
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is published and immutable; draft a new version instead'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_scale_delete_guard BEFORE DELETE ON people_connector_skill_proficiency_scales'
            .' WHEN OLD.published_at IS NOT NULL'
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale has been published and cannot be deleted'); END",
        );

        // Written out one statement per trigger. The schema-drift verifier
        // reads migration source statically: a statement it cannot resolve
        // could be anything, so it is reported unreadable and the whole run
        // comes back INCOMPLETE.
        //
        // The loop is not what it could not resolve, and this matters --
        // a foreach over a literal array with plain concatenation parses
        // fine. Three separate things here were each unresolvable on their
        // own: the strtolower() call, the $notDraft closure call, and the
        // double-quoted interpolation. StaticExpressionEvaluator folds no
        // function call of any kind and has no case for an interpolated
        // string, so removing any one of the three still leaves the rest.
        //
        // Which is why this is not a concession to an analyser. The only
        // thing the loop bought was the $notDraft helper -- and that helper
        // is precisely what cannot be folded, so a loop that keeps the
        // deduplication cannot exist. Every loop shape that does parse ends
        // up spelling all three conditions out anyway and adds indirection
        // on top. Three literal statements read exactly as the database
        // stores them, which has been checked against sqlite_master.
        DB::statement(
            'CREATE TRIGGER pcs_level_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_proficiency_scale_levels'
            ." WHEN (SELECT status FROM people_connector_skill_proficiency_scales WHERE id = NEW.scale_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is not draft; its levels are immutable'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_level_update_guard'
            .' BEFORE UPDATE ON people_connector_skill_proficiency_scale_levels'
            ." WHEN (SELECT status FROM people_connector_skill_proficiency_scales WHERE id = NEW.scale_id) != 'draft' OR (SELECT status FROM people_connector_skill_proficiency_scales WHERE id = OLD.scale_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is not draft; its levels are immutable'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_level_delete_guard'
            .' BEFORE DELETE ON people_connector_skill_proficiency_scale_levels'
            ." WHEN (SELECT status FROM people_connector_skill_proficiency_scales WHERE id = OLD.scale_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'proficiency scale is not draft; its levels are immutable'); END",
        );

        DB::statement(
            'CREATE TRIGGER pcs_skill_code_guard BEFORE UPDATE ON people_connector_skill_skills'
            .' WHEN NEW.code != OLD.code'
            ." BEGIN SELECT RAISE(ABORT, 'skill code is stable and cannot be changed'); END",
        );

        // The company axis has no database backstop of its own: the composite
        // foreign key accepts any entity in the tenant, so a pinned UPDATE can
        // move a catalog row to a sibling company and nothing objects. The one
        // move a catalog row may make is to the survivor of a company merge,
        // and the merge marks the old entity merged-into the survivor before
        // it rewrites anything, so that is the rule the database checks.
        DB::statement(
            'CREATE TRIGGER pcs_category_company_owner_guard BEFORE UPDATE ON people_connector_skill_categories'
            .' WHEN NEW.company_entity_id != OLD.company_entity_id AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = OLD.company_entity_id AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id)"
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_skill_company_owner_guard BEFORE UPDATE ON people_connector_skill_skills'
            .' WHEN NEW.company_entity_id != OLD.company_entity_id AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = OLD.company_entity_id AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id)"
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_scale_company_owner_guard BEFORE UPDATE ON people_connector_skill_proficiency_scales'
            .' WHEN NEW.company_entity_id != OLD.company_entity_id AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = OLD.company_entity_id AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id)"
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
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
                DROP TRIGGER IF EXISTS pcs_category_company_owner_guard_trigger ON people_connector_skill_categories;
                DROP TRIGGER IF EXISTS pcs_skill_company_owner_guard_trigger ON people_connector_skill_skills;
                DROP TRIGGER IF EXISTS pcs_scale_company_owner_guard_trigger ON people_connector_skill_proficiency_scales;
                DROP FUNCTION IF EXISTS pcs_scale_guard();
                DROP FUNCTION IF EXISTS pcs_level_guard();
                DROP FUNCTION IF EXISTS pcs_skill_code_guard();
                DROP FUNCTION IF EXISTS pcs_company_owner_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'pcs_scale_update_guard', 'pcs_scale_delete_guard',
                'pcs_level_insert_guard', 'pcs_level_update_guard', 'pcs_level_delete_guard',
                'pcs_skill_code_guard',
                'pcs_category_company_owner_guard', 'pcs_skill_company_owner_guard', 'pcs_scale_company_owner_guard',
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
