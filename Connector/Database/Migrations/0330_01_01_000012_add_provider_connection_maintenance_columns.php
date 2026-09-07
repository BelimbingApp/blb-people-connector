<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A planned maintenance window on a provider connection (#264).
 *
 * `maintenance_until` in the future means the connection is paused: sync
 * passes skip it and webhook-triggered runs are deferred. A value in the past
 * is not maintenance; ProviderConnection::inMaintenance() clears it on the
 * next read rather than a purge, so nothing else has to remember to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people_connector_connector_provider_connections', function (Blueprint $table): void {
            $table->timestamp('maintenance_until')->nullable()->after('deactivated_at');
            $table->string('maintenance_reason', 200)->nullable()->after('maintenance_until');
        });
    }

    public function down(): void
    {
        Schema::table('people_connector_connector_provider_connections', function (Blueprint $table): void {
            $table->dropColumn(['maintenance_until', 'maintenance_reason']);
        });
    }
};
