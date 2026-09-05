<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople\Services;

use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;

/**
 * The connector's bootstrap port, served by the co-located People reader.
 *
 * Page cursors cross this boundary untouched in both directions: they are
 * People's own encrypted, tenant-bound values, and the connector has no
 * business parsing, re-encoding, or reissuing one.
 */
final readonly class WorkforceBootstrapPort implements BootstrapsWorkforce
{
    public function __construct(
        private ReadsWorkforceBootstrap $reader,
        private WorkforceRecordTranslator $translator,
    ) {}

    public function bootstrap(WorkforcePageRequest $request): WorkforcePage
    {
        try {
            $page = $this->reader->read(new WorkforceBootstrapRequest(
                pageCursor: $request->pageCursor,
                limit: $request->limit,
            ));
        } catch (BlbDataContractException $exception) {
            throw new ProviderValidationException(
                providerId: FirstPartyPeopleAdapter::ID,
                operation: 'bootstrap_workforce',
                message: 'The People provider refused the workforce bootstrap read.',
                previous: $exception,
            );
        }

        return new WorkforcePage(
            employees: array_map($this->translator->employee(...), $page->employees),
            asOf: $page->asOf,
            nextPageCursor: $page->nextPageCursor,
            resumeCursor: $page->resumeCursor,
            complete: $page->complete,
            companies: array_map($this->translator->company(...), $page->companies),
            organizationUnits: array_map($this->translator->organizationUnit(...), $page->organizationUnits),
        );
    }
}
