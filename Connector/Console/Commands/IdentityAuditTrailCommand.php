<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\IdentityAuditTrailException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\IdentityAuditTrail;
use Illuminate\Console\Command;

final class IdentityAuditTrailCommand extends Command
{
    protected $signature = 'connector:identity:audit-trail
                            {external-id : Exact provider external identifier}
                            {--tenant= : Tenant containing the identity; defaults to the current tenant context}
                            {--as= : Id of the operator reading the trail}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Print one external identity audit trail for the operator tenant';

    public function handle(TenantContext $tenants, IdentityAuditTrail $auditTrail): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('An identity audit trail runs as a named operator: pass --as=<user id>.');

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
            $events = $auditTrail->forExternalId(Actor::forUser($operator), (string) $this->argument('external-id'));
        } catch (AuthorizationDeniedException|IdentityAuditTrailException|ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode(['events' => $events], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['event', 'actor', 'occurred at'], $events);
        }

        return self::SUCCESS;
    }
}
