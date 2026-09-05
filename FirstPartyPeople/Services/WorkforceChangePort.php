<?php

namespace App\Domains\PeopleConnector\FirstPartyPeople\Services;

use App\Base\Foundation\Exceptions\BlbDataContractException;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsPeopleWorkforceChanges;
use App\Domains\People\Provider\Data\WorkforceChangeRequest as PeopleWorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\FirstPartyPeople\Exceptions\ForeignProviderReferenceException;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;

/**
 * The incremental half of the same seam.
 *
 * People reports the window it replayed as `since`; the connector's change
 * page has no field for it, because the connector resumes from the cursor it
 * stored rather than from a restated instant. Both cursors still cross
 * untouched.
 */
final readonly class WorkforceChangePort implements ReadsWorkforceChanges
{
    public function __construct(
        private ReadsPeopleWorkforceChanges $reader,
        private WorkforceRecordTranslator $translator,
    ) {}

    public function changes(WorkforceChangeRequest $request): WorkforceChangePage
    {
        try {
            $page = $this->reader->read(new PeopleWorkforceChangeRequest(
                resumeCursor: $request->resumeCursor,
                pageCursor: $request->pageCursor,
                limit: $request->limit,
            ));

            return new WorkforceChangePage(
                changes: array_map($this->translator->change(...), $page->changes),
                asOf: $page->asOf,
                nextPageCursor: $page->nextPageCursor,
                resumeCursor: $page->resumeCursor,
                complete: $page->complete,
            );
        } catch (BlbDataContractException $exception) {
            throw new ProviderValidationException(
                providerId: FirstPartyPeopleAdapter::ID,
                operation: 'read_workforce_changes',
                message: 'The People provider refused the incremental workforce read.',
                previous: $exception,
            );
        } catch (ForeignProviderReferenceException $exception) {
            throw new ProviderValidationException(
                providerId: FirstPartyPeopleAdapter::ID,
                operation: 'read_workforce_changes',
                message: $exception->getMessage(),
                context: ['published_provider_id' => $exception->publishedProviderId],
                previous: $exception,
            );
        }
    }
}
