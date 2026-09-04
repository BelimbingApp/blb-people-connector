<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level enforcement of training-catalog invariants that Skill already
 * carries for its catalog tables (see Skill migration 0330_02_01 createImmutabilityGuards):
 *
 * 1. A course `code` cannot change via any write path — including raw
 *    DB::table()->update() and builder updates that bypass Eloquent events —
 *    and a course row cannot be deleted (codes are stable identities; the
 *    supported lifecycle is deactivate/reactivate).
 * 2. A course `company_entity_id` cannot move to another company except the
 *    documented company-merge shape (old entity already marked merged into
 *    the survivor).
 * 3. A course↔skill mapping cannot re-parent its `course_id` onto a course
 *    owned by a different company, except the same merge shape looked up
 *    through the parent courses — Class D ownership via course_id, the same
 *    inheritance shape as proficiency-scale levels.
 * 4. A course↔skill mapping cannot point at a skill owned by a different
 *    company than its course (on insert, and on update when course_id or
 *    skill_id changes), except the merge-aware company match. Schema FKs
 *    constrain tenant only.
 *
 * Before installing, a legacy-row preflight refuses to proceed when any
 * existing mapping already violates the course/skill company match, so the
 * migration cannot claim an invariant the data does not hold.
 *
 * Model events and CompanyOwnedQuery remain; these triggers are the backstop
 * when those layers are stepped around. Filed as BelimbingApp/blb-people-connector#86.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoCrossCompanyCourseSkillMappings();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            $this->createPostgresGuards();
        } elseif ($driver === 'sqlite') {
            $this->createSqliteGuards();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER IF EXISTS pct_course_code_guard_trigger ON people_connector_training_courses;
                DROP TRIGGER IF EXISTS pct_course_company_owner_guard_trigger ON people_connector_training_courses;
                DROP TRIGGER IF EXISTS pct_course_skill_company_owner_guard_trigger ON people_connector_training_course_skills;
                DROP FUNCTION IF EXISTS pct_course_code_guard();
                DROP FUNCTION IF EXISTS pct_course_company_owner_guard();
                DROP FUNCTION IF EXISTS pct_course_skill_company_owner_guard();
            SQL);
        } elseif ($driver === 'sqlite') {
            foreach ([
                'pct_course_code_guard',
                'pct_course_delete_guard',
                'pct_course_company_owner_guard',
                'pct_course_skill_company_owner_guard',
                'pct_course_skill_company_owner_insert_guard',
            ] as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }
        }
    }

    /**
     * Refuse to install over mappings whose course and skill already sit on
     * different companies. Triggers would only protect future writes.
     */
    private function assertNoCrossCompanyCourseSkillMappings(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('people_connector_training_course_skills')) {
            return;
        }

        $bad = (int) DB::table('people_connector_training_course_skills as cs')
            ->join('people_connector_training_courses as c', function ($join): void {
                $join->on('c.id', '=', 'cs.course_id')
                    ->on('c.tenant_id', '=', 'cs.tenant_id');
            })
            ->join('people_connector_skill_skills as s', function ($join): void {
                $join->on('s.id', '=', 'cs.skill_id')
                    ->on('s.tenant_id', '=', 'cs.tenant_id');
            })
            ->whereColumn('c.company_entity_id', '!=', 's.company_entity_id')
            ->count();

        if ($bad > 0) {
            throw new RuntimeException(
                "Cannot install training-catalog immutability guards: {$bad} course↔skill mapping(s) already cross company owners.",
            );
        }
    }

    private function createPostgresGuards(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION pct_course_code_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'training course code % is stable and cannot be deleted', OLD.code;
                END IF;
                IF NEW.code IS DISTINCT FROM OLD.code THEN
                    RAISE EXCEPTION 'training course code % is stable and cannot be changed', OLD.code;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pct_course_code_guard_trigger
                BEFORE UPDATE OR DELETE ON people_connector_training_courses
                FOR EACH ROW EXECUTE FUNCTION pct_course_code_guard();

            CREATE OR REPLACE FUNCTION pct_course_company_owner_guard() RETURNS trigger AS $$
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

            CREATE TRIGGER pct_course_company_owner_guard_trigger
                BEFORE UPDATE ON people_connector_training_courses
                FOR EACH ROW EXECUTE FUNCTION pct_course_company_owner_guard();

            -- Class D: ownership is inherited through course_id. A pinned
            -- UPDATE that re-parents the mapping onto a sibling company's
            -- course must be refused the same way a direct company_entity_id
            -- move is refused on Class C rows — unless the old course's
            -- company is already marked merged into the new course's company.
            -- Separately, insert/update must keep the referenced skill on the
            -- same company as the referenced course (merge-aware), because the
            -- composite FKs only pin tenant.
            CREATE OR REPLACE FUNCTION pct_course_skill_company_owner_guard() RETURNS trigger AS $$
            DECLARE
                old_company bigint;
                new_company bigint;
                course_company bigint;
                skill_company bigint;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.course_id IS DISTINCT FROM OLD.course_id THEN
                    SELECT company_entity_id INTO old_company
                        FROM people_connector_training_courses
                        WHERE id = OLD.course_id AND tenant_id = OLD.tenant_id;
                    SELECT company_entity_id INTO new_company
                        FROM people_connector_training_courses
                        WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id;
                    IF old_company IS DISTINCT FROM new_company
                        AND NOT EXISTS (
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE id = old_company
                                AND tenant_id = OLD.tenant_id
                                AND state = 'merged'
                                AND merged_into_entity_id = new_company
                        ) THEN
                        RAISE EXCEPTION 'catalog row % belongs to company entity % and cannot move to another company', OLD.id, old_company;
                    END IF;
                END IF;

                IF TG_OP = 'INSERT'
                    OR NEW.course_id IS DISTINCT FROM OLD.course_id
                    OR NEW.skill_id IS DISTINCT FROM OLD.skill_id THEN
                    SELECT company_entity_id INTO course_company
                        FROM people_connector_training_courses
                        WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id;
                    SELECT company_entity_id INTO skill_company
                        FROM people_connector_skill_skills
                        WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id;
                    IF course_company IS DISTINCT FROM skill_company
                        AND NOT EXISTS (
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE id = skill_company
                                AND tenant_id = NEW.tenant_id
                                AND state = 'merged'
                                AND merged_into_entity_id = course_company
                        )
                        AND NOT EXISTS (
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE id = course_company
                                AND tenant_id = NEW.tenant_id
                                AND state = 'merged'
                                AND merged_into_entity_id = skill_company
                        ) THEN
                        RAISE EXCEPTION 'catalog row % belongs to company entity % and cannot move to another company',
                            COALESCE(OLD.id, NEW.id), course_company;
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER pct_course_skill_company_owner_guard_trigger
                BEFORE INSERT OR UPDATE ON people_connector_training_course_skills
                FOR EACH ROW EXECUTE FUNCTION pct_course_skill_company_owner_guard();
        SQL);
    }

    private function createSqliteGuards(): void
    {
        // Written out one statement per trigger. The schema-drift verifier
        // reads migration source statically: keep each statement a plain
        // concatenation of string literals (see Skill createSqliteGuards).
        DB::statement(
            'CREATE TRIGGER pct_course_code_guard BEFORE UPDATE ON people_connector_training_courses'
            .' WHEN NEW.code != OLD.code'
            ." BEGIN SELECT RAISE(ABORT, 'training course code is stable and cannot be changed'); END",
        );

        DB::statement(
            'CREATE TRIGGER pct_course_delete_guard BEFORE DELETE ON people_connector_training_courses'
            ." BEGIN SELECT RAISE(ABORT, 'training course code is stable and cannot be deleted'); END",
        );

        DB::statement(
            'CREATE TRIGGER pct_course_company_owner_guard BEFORE UPDATE ON people_connector_training_courses'
            .' WHEN NEW.company_entity_id != OLD.company_entity_id AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = OLD.company_entity_id AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged' AND merged_into_entity_id = NEW.company_entity_id)"
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
        );

        DB::statement(
            'CREATE TRIGGER pct_course_skill_company_owner_guard BEFORE UPDATE ON people_connector_training_course_skills'
            .' WHEN ('
            .' (NEW.course_id != OLD.course_id'
            .' AND (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)'
            .' != (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = OLD.course_id AND tenant_id = OLD.tenant_id)'
            .' AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = OLD.course_id AND tenant_id = OLD.tenant_id)'
            .' AND tenant_id = OLD.tenant_id'
            ." AND state = 'merged'"
            .' AND merged_into_entity_id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)))'
            .' OR ((NEW.course_id != OLD.course_id OR NEW.skill_id != OLD.skill_id)'
            .' AND (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)'
            .' != (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id)'
            .' AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id)'
            .' AND tenant_id = NEW.tenant_id'
            ." AND state = 'merged'"
            .' AND merged_into_entity_id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id))'
            .' AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)'
            .' AND tenant_id = NEW.tenant_id'
            ." AND state = 'merged'"
            .' AND merged_into_entity_id = (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id))))'
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
        );

        DB::statement(
            'CREATE TRIGGER pct_course_skill_company_owner_insert_guard BEFORE INSERT ON people_connector_training_course_skills'
            .' WHEN (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)'
            .' != (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id)'
            .' AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id)'
            .' AND tenant_id = NEW.tenant_id'
            ." AND state = 'merged'"
            .' AND merged_into_entity_id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id))'
            .' AND NOT EXISTS ('
            .' SELECT 1 FROM people_connector_connector_workforce_entities'
            .' WHERE id = (SELECT company_entity_id FROM people_connector_training_courses'
            .' WHERE id = NEW.course_id AND tenant_id = NEW.tenant_id)'
            .' AND tenant_id = NEW.tenant_id'
            ." AND state = 'merged'"
            .' AND merged_into_entity_id = (SELECT company_entity_id FROM people_connector_skill_skills'
            .' WHERE id = NEW.skill_id AND tenant_id = NEW.tenant_id))'
            ." BEGIN SELECT RAISE(ABORT, 'catalog row belongs to its company entity and cannot move to another company'); END",
        );
    }
};
