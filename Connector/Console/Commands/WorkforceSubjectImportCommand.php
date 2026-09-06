<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSubjectImportException;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSubjectImporter;
use Illuminate\Console\Command;

final class WorkforceSubjectImportCommand extends Command
{
    protected $signature = 'connector:identity-import
                            {package : Package id in protected incoming DataShare storage}
                            {--connection= : Target provider connection id}
                            {--tenant= : Target tenant id; defaults to the current tenant context}
                            {--as= : Id of the operator this import runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Import one subject export identity mapping and history into the current tenant';

    public function handle(TenantContext $tenants, WorkforceSubjectImporter $importer): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '' || ($connectionId = $this->option('connection')) === null || $connectionId === '') {
            $this->error('A subject import requires --connection=<id> and a named operator via --as=<user id>.');

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
            $result = $importer->import(Actor::forUser($operator), (int) $connectionId, (string) $this->argument('package'));
        } catch (ProviderAuthorizationException|WorkforceSubjectImportException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->option('json')
            ? $this->line(json_encode($result->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))
            : $this->info("Imported package [{$result->packageId}] as workforce entity [{$result->workforceEntityId}].");

        return self::SUCCESS;
    }
}
