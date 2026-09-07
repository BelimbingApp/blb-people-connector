<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\CapabilityVerifier;
use Illuminate\Console\Command;

/**
 * Record deployment evidence for one provider capability into the
 * capability register (#231). Exits non-zero when nothing was recorded for
 * a reason other than "already verified".
 */
final class CapabilityVerifyCommand extends Command
{
    protected $signature = 'connector:capability:verify
                            {provider : Provider id, e.g. hr2000.sbg}
                            {capability : PeopleCapability value, e.g. employee_directory}
                            {--evidence= : URL or reference of the deployment evidence}
                            {--tenant= : Tenant the provider is configured in; defaults to the current tenant context}
                            {--as= : Id of the operator recording the evidence}';

    protected $description = 'Record deployment evidence for one provider capability into the capability register';

    public function handle(TenantContext $tenants, CapabilityVerifier $verifier): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('Capability evidence is recorded by a named operator: pass --as=<user id>.');

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
            $result = $verifier->verify(
                Actor::forUser($operator),
                (string) $this->argument('provider'),
                (string) $this->argument('capability'),
                (string) ($this->option('evidence') ?? ''),
            );
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|ConnectorRecordNotFoundException|InvalidProviderConfigurationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line($result->message);
        if ($result->recorded && ! $result->declaredByAdapter) {
            $this->warn("The [{$result->providerId}] adapter does not declare [{$result->capability}] yet; connector:health:check will list it as withdrawn until it does.");
        }

        return self::SUCCESS;
    }
}
