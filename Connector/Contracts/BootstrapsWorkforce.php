<?php

namespace App\Domains\PeopleConnector\Connector\Contracts;

use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;

interface BootstrapsWorkforce extends ReadableProviderPort
{
    public function bootstrap(WorkforcePageRequest $request): WorkforcePage;
}
