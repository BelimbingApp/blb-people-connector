<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\BackupRestorePackage;

interface PublishesBackupRestorePackage
{
    public function publish(int $tenantId, int $operatorId): BackupRestorePackage;
}
