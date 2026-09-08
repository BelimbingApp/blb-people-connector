<?php

use App\Base\Database\Concerns\IncubatingSchema;
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
 *
 * Declares IncubatingSchema because it alters people_connector_connector_provider_connections,
 * which an incubating migration creates. A stable forward onto an incubating table
 * is what IncubatingSchemaConflictException refuses: rebuilding the create alone
 * would drop these columns while the ledger still claimed they were applied.
 * Joining the replay chain means they are dropped and recreated with the table
 * they belong to.
 */
return new class extends Migration
{
    use IncubatingSchema;

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
