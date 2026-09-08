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

    public function up(): void
    {
        Schema::create('people_connector_connector_doctor_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('check', 64);
            $table->string('status', 16);
            $table->unsignedInteger('count');
            $table->timestamp('measured_at');

            $table->index(['tenant_id', 'check', 'measured_at'], 'pc_doctor_snapshot_history_idx');
            $table->foreign('tenant_id', 'pc_doctor_snapshot_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
        });

        $this->registerTable('people_connector_connector_doctor_snapshots');
    }

    public function down(): void
    {
        $this->unregisterTable('people_connector_connector_doctor_snapshots');
        Schema::dropIfExists('people_connector_connector_doctor_snapshots');
    }
};
