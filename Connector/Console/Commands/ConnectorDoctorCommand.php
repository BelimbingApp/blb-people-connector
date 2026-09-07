<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctor;
use App\Domains\PeopleConnector\Connector\Services\ConnectorDoctorAlerter;
use Illuminate\Console\Command;

final class ConnectorDoctorCommand extends Command
{
    protected $signature = 'connector:doctor
                            {--tenant= : Tenant to inspect; defaults to the current tenant context}
                            {--as= : Id of the operator this inspection runs as}
                            {--record : Persist this run as a tenant-scoped health snapshot}
                            {--alert : Record, then alert the operator on checks red twice in a row, and on recovery}
                            {--history= : List the latest recorded snapshot per check within this many days}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Run the tenant-scoped connector operator health checks';

    public function handle(TenantContext $tenants, ConnectorDoctor $doctor, ConnectorDoctorAlerter $alerter): int
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

        $historyDays = $this->option('history');
        $record = $this->option('record') || $this->option('alert');
        if ($record && $historyDays !== null) {
            $this->error('--record, --alert and --history cannot be used together.');

            return self::FAILURE;
        }
        if ($historyDays !== null && (! ctype_digit((string) $historyDays) || (int) $historyDays < 1)) {
            $this->error('--history must be a positive number of days.');

            return self::FAILURE;
        }

        try {
            $actor = Actor::forUser($operator);
            if ($historyDays !== null) {
                $checks = $doctor->history($actor, (int) $historyDays);
                if ($this->option('json')) {
                    $this->line(json_encode(['checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                } else {
                    $this->table(['check', 'status', 'count', 'measured at'], $checks);
                }

                return self::SUCCESS;
            }

            $report = $record ? $doctor->record($actor) : $doctor->inspect($actor);
            $alerts = $this->option('alert') ? $alerter->alert($tenants->requireTenantId(), $report, $operator) : [];
        } catch (AuthorizationDeniedException|ProviderAuthorizationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray() + ($this->option('alert') ? ['alerts' => $alerts] : []), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->table(['check', 'status', 'detail'], $report->checks);
            foreach ($alerts as $line) {
                $this->line($line);
            }
        }

        return $report->healthy() ? self::SUCCESS : self::FAILURE;
    }
}
