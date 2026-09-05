<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\CutoverRehearsalService;
use Illuminate\Console\Command;

/**
 * Rehearse a provider cutover and exit non-zero while anything blocks it.
 *
 * The exit status is the part that matters. A deployment script reads that, not
 * the prose, so a rehearsal reporting blockers and exiting zero would be worse
 * than not running at all.
 */
final class CutoverRehearsalCommand extends Command
{
    protected $signature = 'people-connector:cutover-rehearsal
                            {from : Connection being replaced}
                            {to : Connection taking over}
                            {--tenant= : Tenant to rehearse in; defaults to the current tenant context}
                            {--as= : Id of the operator this rehearsal runs as}';

    protected $description = 'Report what a provider cutover would break, without changing anything';

    public function handle(TenantContext $tenants, CutoverRehearsalService $rehearsals): int
    {
        $operatorId = $this->option('as');

        if ($operatorId === null || $operatorId === '') {
            $this->error('A cutover rehearsal runs as a named operator: pass --as=<user id>.');

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
            $report = $rehearsals->rehearse(
                Actor::forUser($operator),
                (int) $this->argument('from'),
                (int) $this->argument('to'),
            );
        } catch (ProviderAuthorizationException|ConnectorRecordNotFoundException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Cutover rehearsal: connection {$report->fromConnectionId} → {$report->toConnectionId}");
        $this->table(['Check', 'Result'], [
            ['Identities the target cannot answer for', (string) $report->unmappedIdentities],
            ['Target connection stale', $report->targetStale ? ($report->targetStaleReason ?? 'yes') : 'no'],
            ['Open reconciliation issues', (string) $report->openIssues],
        ]);

        if (! $report->blocked()) {
            $this->line('No blockers found. Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($report->blockers() as $blocker) {
            $this->warn('Blocked: '.$blocker);
        }

        return self::FAILURE;
    }
}
