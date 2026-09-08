<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per doctor alert sent (#257). The unique key is what makes a rerun
 * while still red a no-op: an incident is (tenant, check, first red at), and
 * each of its two alerts, red and recovered, goes out once.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_doctor_alerts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('check', 64);
            $table->timestamp('first_red_at');
            $table->string('kind', 16);
            $table->timestamp('sent_at');

            $table->unique(['tenant_id', 'check', 'first_red_at', 'kind'], 'pc_doctor_alert_key');
            $table->index(['tenant_id', 'sent_at'], 'pc_doctor_alert_sent_idx');
            $table->foreign('tenant_id', 'pc_doctor_alert_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_doctor_alerts');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_doctor_alerts');
        Schema::dropIfExists('people_connector_connector_doctor_alerts');
    }
};
