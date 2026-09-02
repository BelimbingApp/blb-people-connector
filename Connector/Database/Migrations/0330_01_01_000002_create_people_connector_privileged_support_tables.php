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
        'people_connector_connector_privileged_support_grants',
        'people_connector_connector_privileged_support_actions',
    ];

    public function up(): void
    {
        Schema::create($this->tables[0], function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('requested_by_user_id');
            $table->unsignedBigInteger('approved_by_user_id');
            $table->text('purpose');
            $table->json('capabilities');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id', 'pc_support_grant_tenant_idx');
            $table->unique(['id', 'tenant_id'], 'pc_support_grant_id_tenant_uq');
            $table->index(['tenant_id', 'company_id', 'expires_at'], 'pc_support_grant_scope_idx');
            $table->foreign('tenant_id', 'pc_support_grant_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['company_id', 'tenant_id'], 'pc_support_grant_company_tenant_fk')
                ->references(['id', 'tenant_id'])->on('companies')->restrictOnDelete();
        });

        Schema::create($this->tables[1], function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('grant_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('action', 120);
            $table->string('outcome', 40);
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['tenant_id', 'grant_id', 'occurred_at'], 'pc_support_action_grant_idx');
            $table->foreign('tenant_id', 'pc_support_action_tenant_fk')
                ->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['grant_id', 'tenant_id'], 'pc_support_action_grant_tenant_fk')
                ->references(['id', 'tenant_id'])
                ->on('people_connector_connector_privileged_support_grants')
                ->restrictOnDelete();
        });

        foreach ($this->tables as $table) {
            $this->registerTable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
