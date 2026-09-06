<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctor;
use Illuminate\Console\Command;

final class ConnectorDoctorCommand extends Command
{
    protected $signature = 'connector:doctor
                            {--tenant= : Tenant to inspect; defaults to the current tenant context}
                            {--as= : Id of the operator this inspection runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Run the tenant-scoped connector operator health checks';

    public function handle(TenantContext $tenants, ConnectorDoctor $doctor): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('Connector doctor runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        if (($tenantId = $this->option('tenant')) !== null && $tenantId !== '') {
            $tenants->set((int) $tenantId);
        }

        try {
            $report = $doctor->inspect(Actor::forUser($operator));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['check', 'status', 'detail'], $report->checks);
        }

        return $report->healthy() ? self::SUCCESS : self::FAILURE;
    }
}
