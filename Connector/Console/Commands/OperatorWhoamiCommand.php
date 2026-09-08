<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\OperatorIdentityReader;

/** Print the acting operator's tenant, company and connector capabilities (#235). Read-only. */
final class OperatorWhoamiCommand extends TenantScopedCommand
{
    protected $signature = 'connector:operator:whoami
                            {--as= : Id of the operator to describe (only inside the tenant)}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Print the acting operator\'s tenant, company and each connector capability as allowed or denied';

    public function handle(TenantContext $tenants, OperatorIdentityReader $reader): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('Name the operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }

        try {
            $identity = $reader->read(Actor::forUser($operator));
        } catch (ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($identity->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->line("Operator {$identity->userId} ({$identity->actorType}) in tenant {$identity->tenantId}, company ".($identity->companyId ?? 'none').'.');
        $this->table(['capability', 'decision', 'reason'], array_map(
            static fn (array $row): array => [$row['capability'], $row['allowed'] ? 'allowed' : 'denied', $row['reason']],
            $identity->capabilities,
        ));

        return self::SUCCESS;
    }
}
