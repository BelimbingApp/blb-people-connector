<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delegated-authority spend ledger (#185): one row per (tenant, jti) that the
 * command port has accepted. The unique key is what makes a second
 * presentation a replay instead of a second command. No tenant foreign key:
 * the tenant id is a signed claim rechecked before the row is written, and a
 * spend must be recordable in the same transaction that refuses the replay.
 */
return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_connector_connector_delegated_spends', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('jti', 64);
            $table->string('subject', 191);
            $table->string('operation', 191);
            $table->timestamp('spent_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'jti'], 'pc_delegated_spend_key');
            $table->index(['tenant_id', 'expires_at'], 'pc_delegated_spend_expiry_idx');
        });

        $this->registerTable('people_connector_connector_delegated_spends');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_delegated_spends');
        Schema::dropIfExists('people_connector_connector_delegated_spends');
    }
};
