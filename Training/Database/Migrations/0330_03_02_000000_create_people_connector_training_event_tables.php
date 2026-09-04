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
        'people_connector_training_events',
        'people_connector_training_event_audit_events',
    ];

    public function up(): void
    {
        Schema::table('people_connector_training_courses', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pct_course_event_owner_uq');
        });

        Schema::create('people_connector_training_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->uuid('event_key');
            $table->unsignedBigInteger('course_id');
            $table->string('course_code_snapshot', 80);
            $table->string('course_title_snapshot');
            $table->string('delivery_mode_snapshot', 24);
            $table->unsignedBigInteger('target_department_entity_id')->nullable();
            $table->unsignedBigInteger('organizer_employee_entity_id');
            $table->unsignedBigInteger('internal_trainer_employee_entity_id')->nullable();
            $table->string('external_trainer_reference', 160)->nullable();
            $table->string('external_trainer_name_snapshot')->nullable();
            $table->string('venue')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->unsignedInteger('capacity');
            $table->string('status', 24);
            $table->timestamp('completed_at')->nullable();
            $table->text('completion_evidence')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pct_event_tenant_idx');
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pct_event_owner_uq');
            $table->unique(['tenant_id', 'event_key'], 'pct_event_key_uq');
            $table->index(['tenant_id', 'company_entity_id', 'status', 'starts_at'], 'pct_event_register_idx');
            $table->foreign('tenant_id', 'pct_event_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['course_id', 'tenant_id', 'company_entity_id'], 'pct_event_course_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_courses')
                ->cascadeOnUpdate()->restrictOnDelete();
            $this->entityReference($table, 'company_entity_id', 'pct_event_company_fk');
            $this->entityReference($table, 'target_department_entity_id', 'pct_event_department_fk');
            $this->entityReference($table, 'organizer_employee_entity_id', 'pct_event_organizer_fk');
            $this->entityReference($table, 'internal_trainer_employee_entity_id', 'pct_event_trainer_fk');
        });

        Schema::create('people_connector_training_event_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('training_event_id');
            $table->string('event_type', 40);
            $table->string('from_status', 24)->nullable();
            $table->string('to_status', 24)->nullable();
            $table->text('comment')->nullable();
            $table->text('evidence')->nullable();
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('actor_employee_entity_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');

            $table->index('tenant_id', 'pct_event_audit_tenant_idx');
            $table->index(['tenant_id', 'company_entity_id', 'training_event_id', 'occurred_at'], 'pct_event_audit_register_idx');
            $table->foreign('tenant_id', 'pct_event_audit_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['training_event_id', 'tenant_id', 'company_entity_id'], 'pct_event_audit_parent_fk')
                ->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_events')
                ->cascadeOnUpdate()->restrictOnDelete();
            $this->entityReference($table, 'actor_employee_entity_id', 'pct_event_audit_actor_fk');
        });

        $this->createAuditGuards();

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        $this->dropAuditGuards();

        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }

        Schema::table('people_connector_training_courses', function (Blueprint $table): void {
            $table->dropUnique('pct_course_event_owner_uq');
        });
    }

    private function entityReference(Blueprint $table, string $column, string $name): void
    {
        $table->foreign([$column, 'tenant_id'], $name)
            ->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete();
    }

    private function createAuditGuards(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE FUNCTION pct_training_event_audit_immutable() RETURNS trigger AS $$
                BEGIN
                    IF TG_OP = 'UPDATE'
                        AND NEW.company_entity_id IS DISTINCT FROM OLD.company_entity_id
                        AND NEW.id = OLD.id
                        AND NEW.tenant_id = OLD.tenant_id
                        AND NEW.training_event_id = OLD.training_event_id
                        AND NEW.event_type = OLD.event_type
                        AND NEW.from_status IS NOT DISTINCT FROM OLD.from_status
                        AND NEW.to_status IS NOT DISTINCT FROM OLD.to_status
                        AND NEW.comment IS NOT DISTINCT FROM OLD.comment
                        AND NEW.evidence IS NOT DISTINCT FROM OLD.evidence
                        AND NEW.actor_user_id IS NOT DISTINCT FROM OLD.actor_user_id
                        AND NEW.actor_employee_entity_id IS NOT DISTINCT FROM OLD.actor_employee_entity_id
                        AND NEW.metadata::jsonb IS NOT DISTINCT FROM OLD.metadata::jsonb
                        AND NEW.occurred_at = OLD.occurred_at
                        AND EXISTS (
                            SELECT 1 FROM people_connector_connector_workforce_entities
                            WHERE tenant_id = OLD.tenant_id
                            AND id = OLD.company_entity_id
                            AND state = 'merged'
                            AND merged_into_entity_id = NEW.company_entity_id
                        ) THEN
                        RETURN NEW;
                    END IF;
                    RAISE EXCEPTION 'training event audit records are append-only';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER pct_training_event_audit_immutable
                    BEFORE UPDATE OR DELETE ON people_connector_training_event_audit_events
                    FOR EACH ROW EXECUTE FUNCTION pct_training_event_audit_immutable();
                SQL);
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER pct_training_event_audit_update_guard
                BEFORE UPDATE ON people_connector_training_event_audit_events
                WHEN NOT (
                    NEW.company_entity_id IS NOT OLD.company_entity_id
                    AND NEW.id IS OLD.id
                    AND NEW.tenant_id IS OLD.tenant_id
                    AND NEW.training_event_id IS OLD.training_event_id
                    AND NEW.event_type IS OLD.event_type
                    AND NEW.from_status IS OLD.from_status
                    AND NEW.to_status IS OLD.to_status
                    AND NEW.comment IS OLD.comment
                    AND NEW.evidence IS OLD.evidence
                    AND NEW.actor_user_id IS OLD.actor_user_id
                    AND NEW.actor_employee_entity_id IS OLD.actor_employee_entity_id
                    AND NEW.metadata IS OLD.metadata
                    AND NEW.occurred_at IS OLD.occurred_at
                    AND EXISTS (
                        SELECT 1 FROM people_connector_connector_workforce_entities
                        WHERE tenant_id = OLD.tenant_id
                        AND id = OLD.company_entity_id
                        AND state = 'merged'
                        AND merged_into_entity_id = NEW.company_entity_id
                    )
                )
                BEGIN SELECT RAISE(ABORT, 'training event audit records are append-only'); END;
                CREATE TRIGGER pct_training_event_audit_delete_guard
                BEFORE DELETE ON people_connector_training_event_audit_events
                BEGIN SELECT RAISE(ABORT, 'training event audit records are append-only'); END;
                SQL);
        }
    }

    private function dropAuditGuards(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS pct_training_event_audit_immutable ON people_connector_training_event_audit_events');
            DB::unprepared('DROP FUNCTION IF EXISTS pct_training_event_audit_immutable()');
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS pct_training_event_audit_update_guard');
            DB::unprepared('DROP TRIGGER IF EXISTS pct_training_event_audit_delete_guard');
        }
    }
};
