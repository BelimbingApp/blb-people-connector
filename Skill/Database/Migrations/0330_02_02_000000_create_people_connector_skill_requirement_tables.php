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
        'people_connector_skill_requirement_profiles',
        'people_connector_skill_requirement_profile_selectors',
        'people_connector_skill_requirement_items',
    ];

    public function up(): void
    {
        $this->createRequirementProfiles();
        $this->createRequirementProfileSelectors();
        $this->createRequirementItems();

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
     * Database-level enforcement of published requirement profile immutability.
     * Following the same pattern as proficiency scales: a published profile
     * cannot be modified except for the published → retired transition, and
     * requirement items under a non-draft profile are immutable.
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
                    AND NEW.id = OLD.id
                    AND NEW.tenant_id = OLD.tenant_id
                    AND NEW.code = OLD.code
                    AND NEW.name = OLD.name
                    AND NEW.version = OLD.version
                    AND NEW.status = OLD.status
                    AND NEW.effective_date IS NOT DISTINCT FROM OLD.effective_date
                    AND NEW.published_at IS NOT DISTINCT FROM OLD.published_at
                    AND NEW.retired_at IS NOT DISTINCT FROM OLD.retired_at
                    AND NEW.owner_employee_entity_id IS NOT DISTINCT FROM OLD.owner_employee_entity_id
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                    SELECT EXISTS(
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE tenant_id = OLD.tenant_id
                        AND id = OLD.company_entity_id
                        AND state = 'merged'
                        AND merged_into_entity_id = NEW.company_entity_id
                    ) INTO is_company_merge;
                    IF is_company_merge THEN
                        RETURN NEW;
                    END IF;
                END IF;
                RAISE EXCEPTION 'requirement profile % is % and immutable; draft a new version instead', OLD.id, OLD.status;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_req_profile_guard_trigger
                BEFORE UPDATE OR DELETE ON people_connector_skill_requirement_profiles
                FOR EACH ROW EXECUTE FUNCTION pcs_req_profile_guard();

            CREATE OR REPLACE FUNCTION pcs_req_item_guard() RETURNS trigger AS $$
            DECLARE
                profile_ids bigint[];
                bad_profile bigint;
                is_company_merge boolean;
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                    AND NEW.id = OLD.id
                    AND NEW.tenant_id = OLD.tenant_id
                    AND NEW.profile_id = OLD.profile_id
                    AND NEW.skill_id = OLD.skill_id
                    AND NEW.sequence = OLD.sequence
                    AND NEW.required_level = OLD.required_level
                    AND NEW.criticality = OLD.criticality
                    AND NEW.weight_percent IS NOT DISTINCT FROM OLD.weight_percent
                    AND NEW.mandatory_gate IS NOT DISTINCT FROM OLD.mandatory_gate
                    AND NEW.reassessment_months IS NOT DISTINCT FROM OLD.reassessment_months
                    AND NEW.active IS NOT DISTINCT FROM OLD.active
                    AND NEW.evidence_standard IS NOT DISTINCT FROM OLD.evidence_standard
                    AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                    SELECT EXISTS(
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE tenant_id = OLD.tenant_id
                        AND id = OLD.company_entity_id
                        AND state = 'merged'
                        AND merged_into_entity_id = NEW.company_entity_id
                    ) INTO is_company_merge;
                    IF is_company_merge THEN
                        RETURN NEW;
                    END IF;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    profile_ids := ARRAY[NEW.profile_id];
                ELSIF TG_OP = 'DELETE' THEN
                    profile_ids := ARRAY[OLD.profile_id];
                ELSE
                    profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                END IF;
                SELECT id INTO bad_profile
                    FROM people_connector_skill_requirement_profiles
                    WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft'
                    LIMIT 1;
                IF bad_profile IS NOT NULL THEN
                    RAISE EXCEPTION 'requirement profile % is not draft; its items are immutable', bad_profile;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_req_item_guard_trigger
                BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_requirement_items
                FOR EACH ROW EXECUTE FUNCTION pcs_req_item_guard();

            CREATE OR REPLACE FUNCTION pcs_req_selector_guard() RETURNS trigger AS $$
            DECLARE
                profile_ids bigint[];
                bad_profile bigint;
                is_merge_carry boolean;
            BEGIN
                -- A company merge may rewrite company_entity_id only, and a
                -- workforce-entity merge may rewrite selector_entity_id only,
                -- when the superseded entity is already marked merged into the
                -- survivor. The signal is that durable merge fact, not a
                -- session flag — so the same rewrite remains permitted after
                -- the merge transaction ends (idempotent move to the survivor).
                -- Every other column on the row must stay unchanged.
                IF TG_OP = 'UPDATE' THEN
                    IF NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id
                        AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.profile_id = OLD.profile_id
                        AND NEW.selector_type = OLD.selector_type
                        AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                        AND NEW.selector_entity_id IS NOT DISTINCT FROM OLD.selector_entity_id
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id
                            AND id = OLD.company_entity_id
                            AND state = 'merged'
                            AND merged_into_entity_id = NEW.company_entity_id
                        ) INTO is_merge_carry;
                        IF is_merge_carry THEN
                            RETURN NEW;
                        END IF;
                    END IF;
                    IF NEW.selector_entity_id IS DISTINCT FROM OLD.selector_entity_id
                        AND NEW.id = OLD.id
                        AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.company_entity_id = OLD.company_entity_id
                        AND NEW.profile_id = OLD.profile_id
                        AND NEW.selector_type = OLD.selector_type
                        AND NEW.selector_value IS NOT DISTINCT FROM OLD.selector_value
                        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at THEN
                        SELECT EXISTS(
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id
                            AND id = OLD.selector_entity_id
                            AND state = 'merged'
                            AND merged_into_entity_id = NEW.selector_entity_id
                        ) INTO is_merge_carry;
                        IF is_merge_carry THEN
                            RETURN NEW;
                        END IF;
                    END IF;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    profile_ids := ARRAY[NEW.profile_id];
                ELSIF TG_OP = 'DELETE' THEN
                    profile_ids := ARRAY[OLD.profile_id];
                ELSE
                    profile_ids := ARRAY[OLD.profile_id, NEW.profile_id];
                END IF;
                SELECT id INTO bad_profile
                    FROM people_connector_skill_requirement_profiles
                    WHERE id = ANY(profile_ids) AND status IS DISTINCT FROM 'draft'
                    LIMIT 1;
                IF bad_profile IS NOT NULL THEN
                    RAISE EXCEPTION 'requirement profile % is not draft; its selectors are immutable', bad_profile;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pcs_req_selector_guard_trigger
                BEFORE INSERT OR UPDATE OR DELETE ON people_connector_skill_requirement_profile_selectors
                FOR EACH ROW EXECUTE FUNCTION pcs_req_selector_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
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
        DB::statement(
            'CREATE TRIGGER pcs_req_profile_delete_guard BEFORE DELETE ON people_connector_skill_requirement_profiles'
            .' WHEN OLD.published_at IS NOT NULL'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile has been published and cannot be deleted'); END",
        );

        DB::statement(
            'CREATE TRIGGER pcs_req_item_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_requirement_items'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its items are immutable'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_req_item_update_guard'
            .' BEFORE UPDATE ON people_connector_skill_requirement_items'
            .' WHEN NOT ('
            ." ((SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) = 'draft'"
            ." AND (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = OLD.profile_id) = 'draft')"
            .' OR (NEW.company_entity_id != OLD.company_entity_id'
            .' AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id AND NEW.profile_id = OLD.profile_id'
            .' AND NEW.skill_id = OLD.skill_id AND NEW.sequence = OLD.sequence'
            .' AND NEW.required_level = OLD.required_level AND NEW.criticality = OLD.criticality'
            .' AND NEW.weight_percent IS OLD.weight_percent AND NEW.mandatory_gate IS OLD.mandatory_gate'
            .' AND NEW.reassessment_months IS OLD.reassessment_months AND NEW.active IS OLD.active'
            .' AND NEW.evidence_standard IS OLD.evidence_standard AND NEW.created_at IS OLD.created_at'
            .' AND EXISTS(SELECT 1 FROM people_connector_connector_workforce_entities'
            ." WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id AND state = 'merged'"
            .' AND merged_into_entity_id = NEW.company_entity_id))'
            .')'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its items are immutable'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_req_item_delete_guard'
            .' BEFORE DELETE ON people_connector_skill_requirement_items'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = OLD.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its items are immutable'); END",
        );

        DB::statement(
            'CREATE TRIGGER pcs_req_selector_insert_guard'
            .' BEFORE INSERT ON people_connector_skill_requirement_profile_selectors'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its selectors are immutable'); END",
        );
        // WHEN NOT (draft | company-merge carry | workforce-entity-merge carry):
        // abort. Merge arms mirror pcs_req_selector_guard() above and the
        // company-merge arm on people_connector_skill_requirement_profiles.
        DB::statement(
            'CREATE TRIGGER pcs_req_selector_update_guard'
            .' BEFORE UPDATE ON people_connector_skill_requirement_profile_selectors'
            .' WHEN NOT ('
            ." ((SELECT status FROM people_connector_skill_requirement_profiles WHERE id = NEW.profile_id) = 'draft'"
            ." AND (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = OLD.profile_id) = 'draft')"
            .' OR (NEW.company_entity_id != OLD.company_entity_id'
            .' AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id AND NEW.profile_id = OLD.profile_id'
            .' AND NEW.selector_type = OLD.selector_type'
            .' AND NEW.selector_value IS OLD.selector_value'
            .' AND NEW.selector_entity_id IS OLD.selector_entity_id'
            .' AND NEW.created_at IS OLD.created_at'
            .' AND EXISTS(SELECT 1 FROM people_connector_connector_workforce_entities'
            ." WHERE tenant_id = OLD.tenant_id AND id = OLD.company_entity_id AND state = 'merged'"
            .' AND merged_into_entity_id = NEW.company_entity_id))'
            .' OR (NEW.selector_entity_id IS NOT OLD.selector_entity_id'
            .' AND NEW.id = OLD.id AND NEW.tenant_id = OLD.tenant_id AND NEW.company_entity_id = OLD.company_entity_id'
            .' AND NEW.profile_id = OLD.profile_id AND NEW.selector_type = OLD.selector_type'
            .' AND NEW.selector_value IS OLD.selector_value AND NEW.created_at IS OLD.created_at'
            .' AND EXISTS(SELECT 1 FROM people_connector_connector_workforce_entities'
            ." WHERE tenant_id = OLD.tenant_id AND id = OLD.selector_entity_id AND state = 'merged'"
            .' AND merged_into_entity_id = NEW.selector_entity_id))'
            .')'
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its selectors are immutable'); END",
        );
        DB::statement(
            'CREATE TRIGGER pcs_req_selector_delete_guard'
            .' BEFORE DELETE ON people_connector_skill_requirement_profile_selectors'
            ." WHEN (SELECT status FROM people_connector_skill_requirement_profiles WHERE id = OLD.profile_id) != 'draft'"
            ." BEGIN SELECT RAISE(ABORT, 'requirement profile is not draft; its selectors are immutable'); END",
        );
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pcs_req_profile_guard_trigger ON people_connector_skill_requirement_profiles;
                DROP TRIGGER IF EXISTS pcs_req_item_guard_trigger ON people_connector_skill_requirement_items;
                DROP TRIGGER IF EXISTS pcs_req_selector_guard_trigger ON people_connector_skill_requirement_profile_selectors;
                DROP FUNCTION IF EXISTS pcs_req_profile_guard();
                DROP FUNCTION IF EXISTS pcs_req_item_guard();
                DROP FUNCTION IF EXISTS pcs_req_selector_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'pcs_req_profile_update_guard', 'pcs_req_profile_delete_guard',
                'pcs_req_item_insert_guard', 'pcs_req_item_update_guard', 'pcs_req_item_delete_guard',
                'pcs_req_selector_insert_guard', 'pcs_req_selector_update_guard', 'pcs_req_selector_delete_guard',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    private function createRequirementProfiles(): void
    {
        Schema::create('people_connector_skill_requirement_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->string('code', 80);
            $table->string('name');
            $table->unsignedInteger('version');
            $table->string('status', 24)->default('draft');
            $table->date('effective_date')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retired_at')->nullable();
            $table->unsignedBigInteger('owner_employee_entity_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_req_profile_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_req_profile_id_tenant_uq');
            $table->unique(['tenant_id', 'company_entity_id', 'code', 'version'], 'pcs_req_profile_version_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status'], 'pcs_req_profile_status_idx');
            $table->index(['tenant_id', 'company_entity_id', 'effective_date'], 'pcs_req_profile_effective_idx');
            $table->foreign('tenant_id', 'pcs_req_profile_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $this->addEntityForeignKey($table, 'company_entity_id', 'pcs_req_profile_company_tenant_fk');
            $this->addEntityForeignKey($table, 'owner_employee_entity_id', 'pcs_req_profile_owner_tenant_fk');
        });
    }

    private function createRequirementProfileSelectors(): void
    {
        Schema::create('people_connector_skill_requirement_profile_selectors', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('profile_id');
            $table->string('selector_type', 40);
            $table->string('selector_value', 255)->nullable();
            $table->unsignedBigInteger('selector_entity_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pcs_req_selector_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_req_selector_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_req_selector_company_idx');
            $table->index(['tenant_id', 'profile_id'], 'pcs_req_selector_profile_idx');
            $table->index(['tenant_id', 'selector_type', 'selector_entity_id'], 'pcs_req_selector_type_idx');
            $table->foreign('tenant_id', 'pcs_req_selector_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['profile_id', 'tenant_id'], 'pcs_req_selector_profile_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_requirement_profiles')
                ->cascadeOnDelete();
            $this->addEntityForeignKey($table, 'selector_entity_id', 'pcs_req_selector_entity_tenant_fk');
        });
    }

    private function createRequirementItems(): void
    {
        Schema::create('people_connector_skill_requirement_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('skill_id');
            $table->unsignedSmallInteger('sequence');
            $table->unsignedTinyInteger('required_level');
            $table->string('criticality', 24);
            $table->decimal('weight_percent', 5, 2);
            $table->text('evidence_standard')->nullable();
            $table->boolean('mandatory_gate')->default(false);
            $table->unsignedSmallInteger('reassessment_months')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('tenant_id', 'pcs_req_item_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pcs_req_item_id_tenant_uq');
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_req_item_company_idx');
            $table->index(['tenant_id', 'profile_id', 'sequence'], 'pcs_req_item_profile_seq_idx');
            $table->unique(['tenant_id', 'profile_id', 'skill_id'], 'pcs_req_item_profile_skill_uq');
            $table->foreign('tenant_id', 'pcs_req_item_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['profile_id', 'tenant_id'], 'pcs_req_item_profile_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_skill_requirement_profiles')
                ->cascadeOnDelete();
            $table->foreign(['skill_id', 'tenant_id'], 'pcs_req_item_skill_tenant_fk')
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
