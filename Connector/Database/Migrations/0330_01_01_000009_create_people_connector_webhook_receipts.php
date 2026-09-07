<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound webhook idempotency ledger (#227): one row per delivery id a
 * tenant has accepted from a provider, with a count of the times it arrived
 * again. The unique key is what makes a second arrival a duplicate instead
 * of a second sync pass. Rows age out after seven days (retention config).
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_webhook_receipts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider_id', 64);
            $table->unsignedBigInteger('connection_id');
            $table->string('delivery_id', 128);
            $table->timestamp('first_seen_at');
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('last_duplicate_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'provider_id', 'delivery_id'], 'pc_webhook_receipt_key');
            $table->index(['tenant_id', 'first_seen_at'], 'pc_webhook_receipt_seen_idx');
            $table->foreign('tenant_id', 'pc_webhook_receipt_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_webhook_receipts');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_webhook_receipts');
        Schema::dropIfExists('people_connector_connector_webhook_receipts');
    }
};
