<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\DataSharePackageApplier;
use App\Base\Database\Services\DataShare\DataSharePackageInbox;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ScratchRestoreResult;
use App\Domains\PeopleConnector\Connector\Exceptions\BackupRestoreRehearsalException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use Illuminate\Support\Facades\DB;

final class ScratchBackupRestoreApplier
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly DataShareScopeCatalog $scopes,
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataSharePackageInbox $inbox,
        private readonly DataShareImportPlanner $planner,
        private readonly DataSharePackageApplier $applier,
    ) {}

    public function apply(Actor $actor): ScratchRestoreResult
    {
        $tenantId = $this->tenants->requireTenantId();
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'backup_restore_rehearsal', 'A backup restore rehearsal runs inside the operator\'s own tenant.');
        }
        $this->authorization->authorize($actor, BackupRestoreRehearsal::CAPABILITY);

        $handoffPath = getenv('PEOPLE_CONNECTOR_REHEARSAL_HANDOFF');
        if (! is_string($handoffPath) || $handoffPath === '' || ! is_file($handoffPath)) {
            throw BackupRestoreRehearsalException::scratchFailed('The private handoff is unavailable.');
        }
        $handoff = json_decode((string) file_get_contents($handoffPath), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($handoff) || ($handoff['tenant_id'] ?? null) !== $tenantId || ! is_array($handoff['tables'] ?? null)
            || ! is_string($handoff['package_path'] ?? null) || ! is_string($handoff['offer'] ?? null)) {
            throw BackupRestoreRehearsalException::scratchFailed('The private handoff is invalid.');
        }

        $registered = array_column($this->scopes->scope(BackupRestorePackagePublisher::SCOPE)->tables, 'table');
        if ($handoff['tables'] !== $registered) {
            throw BackupRestoreRehearsalException::scratchFailed('The handoff table set differs from the registered connector scope.');
        }

        $before = $this->counts($registered, $tenantId, requireGloballyEmpty: true);
        $offer = DataShareTransferOfferBundle::fromJson($handoff['offer']);
        if ($offer->scope !== BackupRestorePackagePublisher::SCOPE || $offer->source->id === $this->instances->current()->id) {
            throw BackupRestoreRehearsalException::scratchFailed('The handoff does not target a distinct scratch instance.');
        }
        $receipt = $this->inbox->receiveFromProtectedPath($handoff['package_path'], DataSharePackageExpectation::fromOffer($offer));
        $plan = $this->planner->plan($receipt);
        if ($plan->status !== 'ready') {
            throw BackupRestoreRehearsalException::scratchFailed('The canonical DataShare plan contains conflicts.');
        }
        $this->applier->apply($plan, $receipt->package_sha256, $plan->plan_hash, confirmed: true);

        return new ScratchRestoreResult($before, $this->counts($registered, $tenantId));
    }

    /** @param list<string> $tables @return array<string, int> */
    private function counts(array $tables, int $tenantId, bool $requireGloballyEmpty = false): array
    {
        $counts = [];
        foreach ($tables as $table) {
            $global = (int) DB::table($table)->count();
            if ($requireGloballyEmpty && $global !== 0) {
                throw BackupRestoreRehearsalException::scratchNotEmpty();
            }
            $counts[$table] = (int) DB::table($table)->where('tenant_id', $tenantId)->count();
        }
        ksort($counts);

        return $counts;
    }
}
