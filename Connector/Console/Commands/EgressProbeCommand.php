<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\EgressProbe;

final class EgressProbeCommand extends TenantScopedCommand
{
    protected $signature = 'connector:probe:egress
                            {--as= : Id of the operator running the probe}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Probe credential-free DNS, TCP, and TLS reachability for active provider connections';

    public function handle(EgressProbe $probe): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('An egress probe runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        try {
            $report = $probe->inspect(Actor::forUser($operator));
        } catch (AuthorizationDeniedException|ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['connection', 'provider', 'host', 'check', 'status', 'detail'], $report->rows());
        }

        return $report->healthy() ? self::SUCCESS : self::FAILURE;
    }
}
