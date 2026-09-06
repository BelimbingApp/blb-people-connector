<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per verified provider callback (#223): which tenant and connection
 * it arrived for, the provider's delivery id, and what became of the sync
 * pass it triggered. A replay is a new row pointing at the one it re-sent.
 * The provider payload is never stored: the callback is a trigger, not a
 * second projection-write path.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('delivery_id', 128);
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('last_error', 191)->nullable();
            $table->unsignedBigInteger('replayed_from_id')->nullable();
            $table->timestamp('received_at');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'connection_id', 'status'], 'pc_webhook_delivery_conn_idx');
            $table->index(['tenant_id', 'delivery_id'], 'pc_webhook_delivery_id_idx');
            $table->foreign('tenant_id', 'pc_webhook_delivery_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_webhook_deliveries');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_webhook_deliveries');
        Schema::dropIfExists('people_connector_connector_webhook_deliveries');
    }
};
