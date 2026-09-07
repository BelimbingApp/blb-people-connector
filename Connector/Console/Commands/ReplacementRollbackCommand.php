<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderReplacementException;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;

/**
 * Reverse a recorded provider replacement while it is still reversible.
 *
 * `--tenant` comes from TenantScopedCommand, which binds the tenant before
 * handle() runs and refuses an unknown one. Exits non-zero on any refusal, so a runbook step that shells out to this
 * command cannot mistake "the boundary was crossed" for "done".
 */
final class ReplacementRollbackCommand extends TenantScopedCommand
{
    protected $signature = 'people-connector:replacement-rollback
                            {audit : Id of the IdentitiesRemapped operator audit to reverse}
                            {--as= : Id of the operator this rollback runs as}
                            {--review= : Review reference for the rollback decision}';

    protected $description = 'Hand remapped identities back to the source connection while nothing has observed them';

    public function handle(ProviderReplacementService $replacements): int
    {
        $operatorId = $this->option('as');

        if ($operatorId === null || $operatorId === '') {
            $this->error('A replacement rollback runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }

        $operator = User::query()->find((int) $operatorId);

        if ($operator === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }

        $review = trim((string) $this->option('review'));

        if ($review === '') {
            $this->error('A replacement rollback reverses a reviewed decision: pass --review=<reference>.');

            return self::FAILURE;
        }

        try {
            $report = $replacements->rollback(Actor::forUser($operator), (int) $this->argument('audit'), $review);
        } catch (ProviderAuthorizationException|ConnectorRecordNotFoundException|ProviderReplacementException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Rolled back {$report->remapped} identity handover(s) from audit {$this->argument('audit')}: connection {$report->fromConnectionId} → {$report->toConnectionId}.");

        return self::SUCCESS;
    }
}
