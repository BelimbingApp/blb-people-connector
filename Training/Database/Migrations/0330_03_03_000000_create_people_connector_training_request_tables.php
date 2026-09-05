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

    public function up(): void
    {
        Schema::table('people_connector_training_courses', function (Blueprint $table): void {
            $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pct_course_request_owner_uq');
        });
        Schema::create('people_connector_training_requests', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->unsignedBigInteger('company_entity_id');
            $table->uuid('request_key'); $table->string('title'); $table->text('business_need');
            $table->unsignedBigInteger('requester_employee_entity_id'); $table->unsignedBigInteger('department_entity_id')->nullable();
            $table->unsignedBigInteger('course_id')->nullable(); $table->string('skill_reference', 160)->nullable();
            $table->string('development_action_reference', 160)->nullable(); $table->unsignedBigInteger('proposed_budget_minor')->nullable();
            $table->char('currency', 3)->nullable(); $table->string('status', 24); $table->unsignedBigInteger('created_by_user_id'); $table->timestamps();
            $table->index('tenant_id', 'pct_request_tenant_idx'); $table->unique(['id', 'tenant_id', 'company_entity_id'], 'pct_request_owner_uq');
            $table->unique(['tenant_id', 'request_key'], 'pct_request_key_uq'); $table->index(['tenant_id', 'company_entity_id', 'status'], 'pct_request_queue_idx');
            $table->foreign('tenant_id', 'pct_request_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['course_id', 'tenant_id', 'company_entity_id'], 'pct_request_course_fk')->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_courses')->restrictOnDelete();
            $this->entityReference($table, 'company_entity_id', 'pct_request_company_fk'); $this->entityReference($table, 'requester_employee_entity_id', 'pct_request_requester_fk'); $this->entityReference($table, 'department_entity_id', 'pct_request_department_fk');
        });
        Schema::create('people_connector_training_request_decisions', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('tenant_id'); $table->unsignedBigInteger('company_entity_id'); $table->unsignedBigInteger('training_request_id');
            $table->string('decision', 32); $table->unsignedBigInteger('actor_user_id'); $table->text('notes')->nullable(); $table->timestamp('occurred_at');
            $table->index(['tenant_id', 'company_entity_id', 'training_request_id', 'occurred_at'], 'pct_request_decision_idx');
            $table->foreign('tenant_id', 'pct_request_decision_tenant_fk')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['training_request_id', 'tenant_id', 'company_entity_id'], 'pct_request_decision_parent_fk')->references(['id', 'tenant_id', 'company_entity_id'])->on('people_connector_training_requests')->cascadeOnUpdate()->restrictOnDelete();
        });
        $this->guards(); $this->registerTable('people_connector_training_requests'); $this->registerTable('people_connector_training_request_decisions');
    }
    public function down(): void
    {
        $this->dropGuards(); $this->unregisterTable('people_connector_training_request_decisions'); $this->unregisterTable('people_connector_training_requests');
        Schema::dropIfExists('people_connector_training_request_decisions'); Schema::dropIfExists('people_connector_training_requests');
        Schema::table('people_connector_training_courses', fn (Blueprint $table) => $table->dropUnique('pct_course_request_owner_uq'));
    }
    private function entityReference(Blueprint $table, string $column, string $name): void { $table->foreign([$column, 'tenant_id'], $name)->references(['id', 'tenant_id'])->on('people_connector_connector_workforce_entities')->restrictOnDelete(); }
    private function guards(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') DB::unprepared("CREATE FUNCTION pct_training_request_decision_immutable() RETURNS trigger AS $$ BEGIN RAISE EXCEPTION 'training request decisions are append-only'; END; $$ LANGUAGE plpgsql; CREATE TRIGGER pct_training_request_decision_immutable BEFORE UPDATE OR DELETE ON people_connector_training_request_decisions FOR EACH ROW EXECUTE FUNCTION pct_training_request_decision_immutable();");
        elseif (DB::connection()->getDriverName() === 'sqlite') DB::unprepared("CREATE TRIGGER pct_training_request_decision_update_guard BEFORE UPDATE ON people_connector_training_request_decisions BEGIN SELECT RAISE(ABORT, 'training request decisions are append-only'); END; CREATE TRIGGER pct_training_request_decision_delete_guard BEFORE DELETE ON people_connector_training_request_decisions BEGIN SELECT RAISE(ABORT, 'training request decisions are append-only'); END;");
    }
    private function dropGuards(): void { if (DB::connection()->getDriverName() === 'pgsql') DB::unprepared('DROP TRIGGER IF EXISTS pct_training_request_decision_immutable ON people_connector_training_request_decisions; DROP FUNCTION IF EXISTS pct_training_request_decision_immutable()'); elseif (DB::connection()->getDriverName() === 'sqlite') DB::unprepared('DROP TRIGGER IF EXISTS pct_training_request_decision_update_guard; DROP TRIGGER IF EXISTS pct_training_request_decision_delete_guard'); }
};
