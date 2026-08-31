<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\ReconciliationReport;

interface ReconcilesWorkforce extends ReadableProviderPort
{
    public function reconcile(): ReconciliationReport;
}
