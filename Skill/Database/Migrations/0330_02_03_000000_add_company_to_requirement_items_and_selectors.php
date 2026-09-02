<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_skill_requirement_profile_selectors', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_entity_id')->nullable()->after('tenant_id');
        });

        Schema::table('people_connector_skill_requirement_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_entity_id')->nullable()->after('tenant_id');
        });

        DB::update(
            'UPDATE people_connector_skill_requirement_profile_selectors s'
            .' SET company_entity_id = (SELECT company_entity_id FROM people_connector_skill_requirement_profiles p WHERE p.id = s.profile_id)'
        );

        DB::update(
            'UPDATE people_connector_skill_requirement_items i'
            .' SET company_entity_id = (SELECT company_entity_id FROM people_connector_skill_requirement_profiles p WHERE p.id = i.profile_id)'
        );

        Schema::table('people_connector_skill_requirement_profile_selectors', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_entity_id')->nullable(false)->change();
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_req_selector_company_idx');
        });

        Schema::table('people_connector_skill_requirement_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_entity_id')->nullable(false)->change();
            $table->index(['tenant_id', 'company_entity_id'], 'pcs_req_item_company_idx');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_requirement_profile_selectors', function (Blueprint $table): void {
            $table->dropIndex('pcs_req_selector_company_idx');
            $table->dropColumn('company_entity_id');
        });

        Schema::table('people_connector_skill_requirement_items', function (Blueprint $table): void {
            $table->dropIndex('pcs_req_item_company_idx');
            $table->dropColumn('company_entity_id');
        });
    }
};
