<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectExportException;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectExporter;
use Illuminate\Console\Command;

final class WorkforceSubjectExportCommand extends Command
{
    protected $signature = 'people-connector:subject-export
                            {entity : Canonical workforce entity id}
                            {--tenant= : Tenant containing the subject; defaults to the current tenant context}
                            {--as= : Id of the operator this export runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Export one workforce identity history as a protected, redacted DataShare package';

    public function handle(TenantContext $tenants, WorkforceSubjectExporter $exporter): int
    {
        $operatorId = $this->option('as');
        if ($operatorId === null || $operatorId === '') {
            $this->error('A subject export runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }

        $operator = User::query()->find((int) $operatorId);
        if ($operator === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }

        $tenantOption = $this->option('tenant');
        if ($tenantOption !== null && $tenantOption !== '') {
            $tenants->set((int) $tenantOption);
        }

        try {
            $result = $exporter->export(Actor::forUser($operator), (int) $this->argument('entity'));
        } catch (ProviderAuthorizationException|WorkforceSubjectExportException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->info("Protected subject package [{$result->packageId}] written to {$result->path} ({$result->bytes} bytes; sha256 {$result->sha256}).");
        }

        return self::SUCCESS;
    }
}
