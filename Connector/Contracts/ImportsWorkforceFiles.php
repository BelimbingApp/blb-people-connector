<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ProviderFile;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileImportResult;
use App\Domains\PeopleConnector\Connector\Data\ProviderFileInspection;

interface ImportsWorkforceFiles extends ReadableProviderPort
{
    public function inspect(ProviderFile $file): ProviderFileInspection;

    /** Atomically re-inspect the exact file and import only when it is accepted. */
    public function inspectAndImport(ProviderFile $file): ProviderFileImportResult;
}
