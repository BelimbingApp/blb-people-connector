<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\TenantMoveDryRun;

/**
 * Report what a tenant move would touch and any blocker, writing nothing
 * (#240). Exits non-zero while any blocker exists: the exit status is what a
 * runbook reads.
 */
final class MigrateDryRunCommand extends TenantScopedCommand
{
    protected $signature = 'connector:migrate:dry-run
                            {--to= : Target tenant id}
                            {--as= : Id of the operator this dry run runs as}
                            {--json : Emit machine-readable result JSON}';

    protected $description = 'Report what a tenant move would touch and any blockers, without writing';

    /**
     * The source tenant is the one bound by --tenant (#249): a dry run reads
     * the tenant it runs in and names only the target separately.
     */
    public function handle(TenantMoveDryRun $dryRun, TenantContext $tenants): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A tenant move dry run runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        $target = $this->option('to');
        if (! is_numeric($target)) {
            $this->error('Name the target tenant: pass --to=<tenant id>.');

            return self::FAILURE;
        }

        try {
            $report = $dryRun->report(Actor::forUser($operator), $tenants->requireTenantId(), (int) $target);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|InvalidProviderConfigurationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report->toArray(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->line("Tenant move dry run: {$report->sourceTenantId} → {$report->targetTenantId}. Nothing was written.");
            $this->table(['table', 'rows'], array_map(static fn (string $t, int $n): array => [$t, (string) $n], array_keys($report->tables), $report->tables));
            $this->table(['source identity', 'target identity', 'resource type', 'external id hash'], array_map(static fn (array $c): array => [(string) $c['source_identity'], (string) $c['target_identity'], $c['resource_type'], $c['external_id_hash']], $report->collisions));
            $this->line("Deliveries in flight: {$report->inFlightDeliveries}. Dead letters: {$report->deadLetters}.");
            foreach ($report->blockers() as $blocker) {
                $this->warn('Blocked: '.$blocker);
            }
            if (! $report->blocked()) {
                $this->line('No blockers found.');
            }
        }

        return $report->blocked() ? self::FAILURE : self::SUCCESS;
    }
}
