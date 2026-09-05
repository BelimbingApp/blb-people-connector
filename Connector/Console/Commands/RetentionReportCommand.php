<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\RetentionTableReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use Illuminate\Console\Command;

/**
 * Show what a retention purge would find, without purging anything.
 *
 * The run names a real operator rather than a console principal. Retention is
 * capability-gated, and a command that invented its own actor would answer the
 * question "may this person see this" with "a command asked, so yes".
 */
final class RetentionReportCommand extends Command
{
    protected $signature = 'people-connector:retention-report
                            {--tenant= : Tenant to report on; defaults to the current tenant context}
                            {--as= : Id of the operator this report runs as}';

    protected $description = 'Report connector-owned rows past their retention window, without deleting any';

    public function handle(TenantContext $tenants, RetentionPolicy $retention): int
    {
        $operatorId = $this->option('as');

        if ($operatorId === null || $operatorId === '') {
            $this->error('A retention report runs as a named operator: pass --as=<user id>.');

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
            $report = $retention->review(Actor::forUser($operator));
        } catch (ProviderAuthorizationException|RetentionPolicyException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Retention review for tenant {$report->tenantId} at {$report->reviewedAt->format(DATE_ATOM)}");
        $this->table(
            ['Table', 'Retention', 'Measured from', 'Past retention'],
            array_map(static fn (RetentionTableReport $t): array => [
                $t->table,
                $t->isIndefinite() ? 'indefinite' : $t->days.' days',
                $t->column ?? '—',
                $t->isIndefinite() ? '—' : (string) $t->expired,
            ], array_values($report->tables)),
        );
        $this->line("Rows past retention: {$report->totalExpired()}. Nothing was deleted.");

        return self::SUCCESS;
    }
}
