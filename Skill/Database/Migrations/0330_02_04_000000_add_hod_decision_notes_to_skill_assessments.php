<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->text('hod_decision_notes')->nullable()->after('hod_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_skill_assessments', function (Blueprint $table): void {
            $table->dropColumn('hod_decision_notes');
        });
    }
};
