<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Domains\PeopleConnector\Connector\Contracts\PublishesBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\BackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Exceptions\BackupRestoreRehearsalException;
use Illuminate\Support\Facades\DB;

final class BackupRestorePackagePublisher implements PublishesBackupRestorePackage
{
    public const SCOPE = 'app/Domains/PeopleConnector/Connector';

    public function __construct(
        private readonly DataShareScopeCatalog $scopes,
        private readonly DataSharePackageExporter $exporter,
        private readonly DataShareTransferOfferManager $offers,
    ) {}

    public function publish(int $tenantId, int $operatorId): BackupRestorePackage
    {
        $tables = array_column($this->scopes->scope(self::SCOPE)->tables, 'table');
        $counts = [];

        foreach ($tables as $table) {
            $counts[$table] = (int) DB::table($table)->where('tenant_id', $tenantId)->count();

            if ((int) DB::table($table)->count() !== $counts[$table]) {
                throw BackupRestoreRehearsalException::multiTenantScope();
            }
        }
        ksort($counts);

        $preview = $this->exporter->preview(self::SCOPE, $tables);
        $bundle = $this->offers->publish(self::SCOPE, $tables, $preview->previewHash, actorId: $operatorId);
        $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->sole();

        return new BackupRestorePackage(
            tenantId: $tenantId,
            tables: $tables,
            sourceCounts: $counts,
            redactions: $preview->advisories,
            protectedPackagePath: (string) $offer->package_path,
            offerJson: $bundle->toJson(),
        );
    }
}
