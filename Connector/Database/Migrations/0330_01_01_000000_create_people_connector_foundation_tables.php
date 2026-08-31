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
        'people_connector_connector_provider_connections',
        'people_connector_connector_workforce_entities',
        'people_connector_connector_external_identities',
        'people_connector_connector_workforce_companies',
        'people_connector_connector_workforce_organization_units',
        'people_connector_connector_workforce_positions',
        'people_connector_connector_workforce_employees',
        'people_connector_connector_workforce_snapshots',
        'people_connector_connector_sync_checkpoints',
        'people_connector_connector_sync_checkpoint_events',
        'people_connector_connector_reconciliation_issues',
    ];

    public function up(): void
    {
        $this->createProviderConnections();
        $this->createWorkforceEntities();
        $this->createExternalIdentities();
        $this->createCompanyProjections();
        $this->createOrganizationUnitProjections();
        $this->createPositionProjections();
        $this->createEmployeeProjections();
        $this->createWorkforceSnapshots();
        $this->createSyncCheckpoints();
        $this->createSyncCheckpointEvents();
        $this->createReconciliationIssues();

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

    private function createProviderConnections(): void
    {
        Schema::create('people_connector_connector_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->string('scope_key', 80);
            $table->string('active_scope_key', 80)->nullable();
            $table->string('provider_id', 100);
            $table->string('label')->nullable();
            $table->string('status', 24)->default('inactive');
            $table->string('adapter_version', 50)->nullable();
            $table->string('contract_version', 50)->nullable();
            $table->json('public_metadata')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_conn_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_conn_id_tenant_uq');
            $table->unique(['tenant_id', 'scope_key', 'provider_id'], 'pc_conn_scope_provider_uq');
            $table->unique(['tenant_id', 'active_scope_key'], 'pc_conn_active_scope_uq');
            $table->index(['tenant_id', 'company_id'], 'pc_conn_company_idx');
            $table->foreign('tenant_id', 'pc_conn_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'], 'pc_conn_company_tenant_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
        });
    }

    private function createWorkforceEntities(): void
    {
        Schema::create('people_connector_connector_workforce_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('resource_type', 40);
            $table->string('state', 24)->default('active');
            $table->unsignedBigInteger('merged_into_entity_id')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamp('merged_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_entity_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_entity_id_tenant_uq');
            $table->index(['tenant_id', 'resource_type', 'state'], 'pc_entity_type_state_idx');
            $table->foreign('tenant_id', 'pc_entity_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['merged_into_entity_id', 'tenant_id'], 'pc_entity_merge_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
        });
    }

    private function createExternalIdentities(): void
    {
        Schema::create('people_connector_connector_external_identities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('replaced_by_identity_id')->nullable();
            $table->string('provider_id', 100);
            $table->string('resource_type', 40);
            $table->string('external_id', 512);
            $table->char('external_id_hash', 64);
            $table->string('state', 24)->default('active');
            $table->string('source_version', 100)->nullable();
            $table->timestamp('effective_from');
            $table->timestamp('effective_to')->nullable();
            $table->timestamp('last_observed_at');
            $table->json('provenance')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_identity_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_identity_id_tenant_uq');
            $table->unique(
                ['tenant_id', 'connection_id', 'resource_type', 'external_id_hash'],
                'pc_identity_external_uq',
            );
            $table->index(['tenant_id', 'workforce_entity_id', 'state'], 'pc_identity_entity_state_idx');
            $table->foreign('tenant_id', 'pc_identity_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['connection_id', 'tenant_id'], 'pc_identity_conn_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_provider_connections')
                ->restrictOnDelete();
            $table->foreign(['workforce_entity_id', 'tenant_id'], 'pc_identity_entity_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['replaced_by_identity_id', 'tenant_id'], 'pc_identity_replace_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_external_identities')
                ->restrictOnDelete();
        });
    }

    private function createCompanyProjections(): void
    {
        Schema::create('people_connector_connector_workforce_companies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('source_identity_id');
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('active');
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source_version', 100)->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_company_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_company_id_tenant_uq');
            $table->unique(['tenant_id', 'workforce_entity_id'], 'pc_company_entity_uq');
            $this->addProjectionForeignKeys($table, 'pc_company');
        });
    }

    private function createOrganizationUnitProjections(): void
    {
        Schema::create('people_connector_connector_workforce_organization_units', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('source_identity_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('parent_entity_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('kind', 80)->nullable();
            $table->boolean('active');
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source_version', 100)->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_org_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_org_id_tenant_uq');
            $table->unique(['tenant_id', 'workforce_entity_id'], 'pc_org_entity_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pc_org_company_active_idx');
            $this->addProjectionForeignKeys($table, 'pc_org');
            $this->addEntityForeignKey($table, 'company_entity_id', 'pc_org_company_tenant_fk');
            $this->addEntityForeignKey($table, 'parent_entity_id', 'pc_org_parent_tenant_fk');
        });
    }

    private function createPositionProjections(): void
    {
        Schema::create('people_connector_connector_workforce_positions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('source_identity_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('organization_entity_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('tier', 100)->nullable();
            $table->boolean('active');
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source_version', 100)->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_position_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_position_id_tenant_uq');
            $table->unique(['tenant_id', 'workforce_entity_id'], 'pc_position_entity_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pc_position_company_active_idx');
            $this->addProjectionForeignKeys($table, 'pc_position');
            $this->addEntityForeignKey($table, 'company_entity_id', 'pc_position_company_tenant_fk');
            $this->addEntityForeignKey($table, 'organization_entity_id', 'pc_position_org_tenant_fk');
        });
    }

    private function createEmployeeProjections(): void
    {
        Schema::create('people_connector_connector_workforce_employees', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('source_identity_id');
            $table->unsignedBigInteger('company_entity_id');
            $table->unsignedBigInteger('user_entity_id')->nullable();
            $table->unsignedBigInteger('organization_entity_id')->nullable();
            $table->unsignedBigInteger('position_entity_id')->nullable();
            $table->unsignedBigInteger('manager_entity_id')->nullable();
            $table->unsignedBigInteger('department_head_entity_id')->nullable();
            $table->string('display_name');
            $table->string('employee_number')->nullable();
            $table->string('email')->nullable();
            $table->boolean('active');
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source_version', 100)->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_employee_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_employee_id_tenant_uq');
            $table->unique(['tenant_id', 'workforce_entity_id'], 'pc_employee_entity_uq');
            $table->index(['tenant_id', 'company_entity_id', 'active'], 'pc_employee_company_active_idx');
            $table->index(['tenant_id', 'employee_number'], 'pc_employee_number_idx');
            $this->addProjectionForeignKeys($table, 'pc_employee');
            $this->addEntityForeignKey($table, 'company_entity_id', 'pc_employee_company_tenant_fk');
            $this->addEntityForeignKey($table, 'user_entity_id', 'pc_employee_user_tenant_fk');
            $this->addEntityForeignKey($table, 'organization_entity_id', 'pc_employee_org_tenant_fk');
            $this->addEntityForeignKey($table, 'position_entity_id', 'pc_employee_position_tenant_fk');
            $this->addEntityForeignKey($table, 'manager_entity_id', 'pc_employee_manager_tenant_fk');
            $this->addEntityForeignKey($table, 'department_head_entity_id', 'pc_employee_head_tenant_fk');
        });
    }

    private function createWorkforceSnapshots(): void
    {
        Schema::create('people_connector_connector_workforce_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('workforce_entity_id');
            $table->unsignedBigInteger('external_identity_id')->nullable();
            $table->string('event_type', 40);
            $table->string('resource_type', 40);
            $table->char('event_key', 64);
            $table->timestamp('effective_at');
            $table->timestamp('observed_at');
            $table->string('source_version', 100)->nullable();
            $table->json('payload');
            $table->json('provenance')->nullable();
            $table->timestamp('created_at');

            $table->index('tenant_id', 'pc_snapshot_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_snapshot_id_tenant_uq');
            $table->unique(['tenant_id', 'event_key'], 'pc_snapshot_event_uq');
            $table->index(['tenant_id', 'workforce_entity_id', 'observed_at'], 'pc_snapshot_entity_time_idx');
            $table->foreign('tenant_id', 'pc_snapshot_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['connection_id', 'tenant_id'], 'pc_snapshot_conn_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_provider_connections')
                ->restrictOnDelete();
            $table->foreign(['workforce_entity_id', 'tenant_id'], 'pc_snapshot_entity_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
            $table->foreign(['external_identity_id', 'tenant_id'], 'pc_snapshot_identity_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_external_identities')
                ->restrictOnDelete();
        });
    }

    private function createSyncCheckpoints(): void
    {
        Schema::create('people_connector_connector_sync_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('stream', 100);
            $table->text('resume_cursor');
            $table->unsignedBigInteger('version')->default(0);
            $table->timestamp('as_of_at');
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index('tenant_id', 'pc_checkpoint_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_checkpoint_id_tenant_uq');
            $table->unique(['tenant_id', 'connection_id', 'stream'], 'pc_checkpoint_stream_uq');
            $table->foreign('tenant_id', 'pc_checkpoint_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['connection_id', 'tenant_id'], 'pc_checkpoint_conn_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_provider_connections')
                ->restrictOnDelete();
        });
    }

    private function createSyncCheckpointEvents(): void
    {
        Schema::create('people_connector_connector_sync_checkpoint_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('checkpoint_id');
            $table->unsignedBigInteger('version');
            $table->text('from_cursor')->nullable();
            $table->text('to_cursor');
            $table->timestamp('as_of_at');
            $table->timestamp('completed_at');
            $table->timestamp('created_at');

            $table->index('tenant_id', 'pc_checkpoint_event_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_checkpoint_event_id_tenant_uq');
            $table->unique(['tenant_id', 'checkpoint_id', 'version'], 'pc_checkpoint_event_version_uq');
            $table->foreign('tenant_id', 'pc_checkpoint_event_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['checkpoint_id', 'tenant_id'], 'pc_checkpoint_event_checkpoint_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_sync_checkpoints')
                ->restrictOnDelete();
        });
    }

    private function createReconciliationIssues(): void
    {
        Schema::create('people_connector_connector_reconciliation_issues', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->unsignedBigInteger('workforce_entity_id')->nullable();
            $table->string('issue_key', 191);
            $table->string('kind', 80);
            $table->string('resource_type', 40)->nullable();
            $table->string('external_id', 512)->nullable();
            $table->string('severity', 24)->default('warning');
            $table->string('status', 24)->default('open');
            $table->json('details')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_recon_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_recon_id_tenant_uq');
            $table->unique(['tenant_id', 'connection_id', 'issue_key'], 'pc_recon_issue_uq');
            $table->index(['tenant_id', 'connection_id', 'status'], 'pc_recon_status_idx');
            $table->foreign('tenant_id', 'pc_recon_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['connection_id', 'tenant_id'], 'pc_recon_conn_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_provider_connections')
                ->restrictOnDelete();
            $table->foreign(['workforce_entity_id', 'tenant_id'], 'pc_recon_entity_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_workforce_entities')
                ->restrictOnDelete();
        });
    }

    private function addProjectionForeignKeys(Blueprint $table, string $prefix): void
    {
        $table->foreign('tenant_id', $prefix.'_tenant_fk')
            ->references('id')->on('tenants')->restrictOnDelete();
        $this->addEntityForeignKey($table, 'workforce_entity_id', $prefix.'_entity_tenant_fk');
        $table->foreign(['source_identity_id', 'tenant_id'], $prefix.'_identity_tenant_fk')
            ->references(['id', 'tenant_id'])
            ->on('people_connector_connector_external_identities')
            ->restrictOnDelete();
    }

    private function addEntityForeignKey(Blueprint $table, string $column, string $name): void
    {
        $table->foreign([$column, 'tenant_id'], $name)
            ->references(['id', 'tenant_id'])
            ->on('people_connector_connector_workforce_entities')
            ->restrictOnDelete();
    }
};
