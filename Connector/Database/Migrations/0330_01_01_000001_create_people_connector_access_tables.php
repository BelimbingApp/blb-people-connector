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

    /** @var list<string> */
    private array $tables = [
        'people_connector_connector_provider_credentials',
    ];

    public function up(): void
    {
        Schema::create($this->tables[0], function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('connection_id');
            $table->string('provider_id', 100);
            $table->string('credential_id', 80);
            $table->string('key_id', 120);
            $table->string('secret_reference', 255);
            $table->string('audience', 128);
            $table->json('scopes');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_credential_tenant_idx');
            $table->unique('credential_id', 'pc_credential_id_uq');
            $table->index(['tenant_id', 'connection_id', 'revoked_at'], 'pc_credential_connection_idx');
            $table->index(['tenant_id', 'audience', 'expires_at'], 'pc_credential_lookup_idx');
            $table->foreign('tenant_id', 'pc_credential_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['connection_id', 'tenant_id'], 'pc_credential_connection_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_provider_connections')
                ->restrictOnDelete();
        });

        $this->registerTable($this->tables[0]);
    }

    public function down(): void
    {
        $this->unregisterTable($this->tables[0]);
        Schema::dropIfExists($this->tables[0]);
    }
};
