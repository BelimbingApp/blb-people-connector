<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\BackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\ScratchRestoreResult;

interface RestoresBackupRestorePackage
{
    public function restore(BackupRestorePackage $package, int $operatorId): ScratchRestoreResult;
}
