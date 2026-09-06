<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use App\Domains\PeopleConnector\Connector\Services\RetentionPurger;
use Illuminate\Console\Command;

final class RetentionPurgeCommand extends Command
{
    protected $signature = 'people-connector:retention-purge
                            {--tenant= : Tenant to purge; defaults to the current tenant context}
                            {--as= : Id of the operator this purge runs as}
                            {--yes : Confirm the displayed report non-interactively}';

    protected $description = 'Review and purge exactly the connector rows currently past retention';

    public function handle(TenantContext $tenants, RetentionPolicy $retention, RetentionPurger $purger): int
    {
        $operatorId = $this->option('as');
        if ($operatorId === null || $operatorId === '') {
            $this->error('A retention purge runs as a named operator: pass --as=<user id>.');

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
            $actor = Actor::forUser($operator);
            $report = $retention->review($actor);
            $this->line("Retention review for tenant {$report->tenantId} at {$report->reviewedAt->format(DATE_ATOM)}");
            foreach ($report->tables as $table) {
                if (! $table->isIndefinite()) {
                    $this->line("{$table->table}: {$table->expired} past {$table->days} days");
                }
            }
            $this->line("Rows eligible for purge: {$report->totalExpired()}.");

            if (! $this->option('yes') && ! $this->confirm('Purge exactly the rows in this report?')) {
                $this->info('Retention purge cancelled. Nothing was deleted.');

                return self::SUCCESS;
            }

            $result = $purger->purge($actor, $report);
        } catch (ProviderAuthorizationException|RetentionPolicyException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->info("Retention purge {$result->runId} deleted {$result->totalDeleted()} rows.");

        return self::SUCCESS;
    }
}
