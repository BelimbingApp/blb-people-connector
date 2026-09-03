<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacy deletion clocks and snapshot redaction markers for connector-owned
 * workforce projections (blb-people#24 / blb-people-connector#54).
 *
 * `privacy_deleted_at` starts when a company-scoped erasure request tombstones
 * personal fields. It is never derived from `updated_at` or ownership moves.
 * `redacted_at` marks an append-only snapshot whose payload was cleared in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_connector_workforce_employees', function (Blueprint $table): void {
            $table->timestamp('privacy_deleted_at')->nullable()->after('source_version');
            $table->index(['tenant_id', 'company_entity_id', 'privacy_deleted_at'], 'pc_employee_privacy_idx');
        });

        Schema::table('people_connector_connector_workforce_organization_units', function (Blueprint $table): void {
            $table->timestamp('privacy_deleted_at')->nullable()->after('source_version');
            $table->index(['tenant_id', 'company_entity_id', 'privacy_deleted_at'], 'pc_org_privacy_idx');
        });

        Schema::table('people_connector_connector_workforce_positions', function (Blueprint $table): void {
            $table->timestamp('privacy_deleted_at')->nullable()->after('source_version');
            $table->index(['tenant_id', 'company_entity_id', 'privacy_deleted_at'], 'pc_position_privacy_idx');
        });

        Schema::table('people_connector_connector_workforce_snapshots', function (Blueprint $table): void {
            $table->timestamp('redacted_at')->nullable()->after('provenance');
            $table->index(['tenant_id', 'redacted_at'], 'pc_snapshot_redacted_idx');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_connector_workforce_employees', function (Blueprint $table): void {
            $table->dropIndex('pc_employee_privacy_idx');
            $table->dropColumn('privacy_deleted_at');
        });

        Schema::table('people_connector_connector_workforce_organization_units', function (Blueprint $table): void {
            $table->dropIndex('pc_org_privacy_idx');
            $table->dropColumn('privacy_deleted_at');
        });

        Schema::table('people_connector_connector_workforce_positions', function (Blueprint $table): void {
            $table->dropIndex('pc_position_privacy_idx');
            $table->dropColumn('privacy_deleted_at');
        });

        Schema::table('people_connector_connector_workforce_snapshots', function (Blueprint $table): void {
            $table->dropIndex('pc_snapshot_redacted_idx');
            $table->dropColumn('redacted_at');
        });
    }
};
