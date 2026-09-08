<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\PublishesBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Contracts\RestoresBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\BackupRestoreRehearsalReport;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;

final class BackupRestoreRehearsal
{
    public const CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly PublishesBackupRestorePackage $publisher,
        private readonly RestoresBackupRestorePackage $restorer,
    ) {}

    public function run(Actor $actor): BackupRestoreRehearsalReport
    {
        $tenantId = $this->tenants->requireTenantId();
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'backup_restore_rehearsal', 'A backup restore rehearsal runs inside the operator\'s own tenant.');
        }
        $this->authorization->authorize($actor, self::CAPABILITY);

        $package = $this->publisher->publish($tenantId, (int) $actor->id);
        $scratch = $this->restorer->restore($package, (int) $actor->id);
        $source = $package->sourceCounts;
        $before = $scratch->before;
        $after = $scratch->after;
        ksort($source);
        ksort($before);
        ksort($after);
        $mismatches = [];

        foreach (array_values(array_unique([...array_keys($source), ...array_keys($after)])) as $table) {
            $count = $source[$table] ?? -1;
            if (($after[$table] ?? -1) !== $count) {
                $mismatches[$table] = ['source' => $count, 'restored' => $after[$table] ?? -1];
            }
        }

        return new BackupRestoreRehearsalReport($tenantId, $source, $before, $after, $package->redactions, $mismatches);
    }
}
