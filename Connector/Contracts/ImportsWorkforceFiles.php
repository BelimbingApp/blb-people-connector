<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileImportResult;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileInspection;

interface ImportsWorkforceFiles extends ProviderPort
{
    public function inspect(ProviderFile $file): ProviderFileInspection;

    public function import(ProviderFile $file): ProviderFileImportResult;
}
