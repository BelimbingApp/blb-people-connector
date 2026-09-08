<?php

namespace App\Domains\PeopleConnector\Connector\Console\Commands;

use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Tenancy\Console\TenantScopedCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Services\SupportBundleBuilder;
use DateTimeImmutable;

/** Write one privacy-safe diagnostics zip for the operator's tenant (#250). */
final class SupportBundleCommand extends TenantScopedCommand
{
    protected $signature = 'connector:support:bundle
                            {--since=7d : Window for history and runs: <n>d, <n>h or <n>m}
                            {--out= : Directory to write into; defaults to storage/app/people-connector/support}
                            {--as= : Id of the operator this bundle runs as}';

    protected $description = 'Write a privacy-safe support bundle (doctor history, sync runs, webhook and reconciliation counts, retention, versions, redacted config)';

    public function handle(TenantContext $tenants, SupportBundleBuilder $builder): int
    {
        if (($operatorId = $this->option('as')) === null || $operatorId === '') {
            $this->error('A support bundle runs as a named operator: pass --as=<user id>.');

            return self::FAILURE;
        }
        if (($operator = User::query()->find((int) $operatorId)) === null) {
            $this->error("No user [{$operatorId}].");

            return self::FAILURE;
        }
        if (preg_match('/^(\d+)([dhm])$/', (string) $this->option('since'), $m) !== 1 || (int) $m[1] < 1) {
            $this->error('--since takes <n>d, <n>h or <n>m, for example 7d.');

            return self::FAILURE;
        }
        $unit = ['d' => 'days', 'h' => 'hours', 'm' => 'minutes'][$m[2]];
        $since = DateTimeImmutable::createFromInterface(now())->modify("-{$m[1]} {$unit}");
        $out = $this->option('out');

        try {
            $bundle = $builder->build(Actor::forUser($operator), $since, is_string($out) && $out !== '' ? $out : null);
        } catch (AuthorizationDeniedException|ProviderAuthorizationException|InvalidProviderConfigurationException $refusal) {
            $this->error($refusal->getMessage());

            return self::FAILURE;
        }

        $this->line("Wrote {$bundle->path} ({$bundle->bytes} bytes).");
        $this->line('Redaction rules applied: '.($bundle->manifest['redaction_rules_applied'] === [] ? 'none' : implode(', ', $bundle->manifest['redaction_rules_applied'])).'.');

        return self::SUCCESS;
    }
}
