<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;

interface ReadsWorkforceChanges extends ReadableProviderPort
{
    public function changes(WorkforceChangeRequest $request): WorkforceChangePage;
}
