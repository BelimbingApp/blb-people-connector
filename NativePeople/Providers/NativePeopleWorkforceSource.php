<?php

namespace App\Domains\PeopleConnector\NativePeople\Providers;

use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap as ReadsNativeWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsNativeWorkforceChanges;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest as NativeBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceChangeRequest as NativeChangeRequest;
use App\Domains\People\Provider\Data\WorkforceDeactivation as NativeDeactivation;
use App\Domains\People\Provider\Data\WorkforceUpsert as NativeUpsert;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceBootstrapCursorException;
use App\Domains\People\Provider\Exceptions\InvalidWorkforceChangeCursorException;
use App\Domains\People\Provider\Exceptions\WorkforceProjectionException;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderCompatibilityException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\NativePeople\NativePeopleWorkforceMapper;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;

final readonly class NativePeopleWorkforceSource implements BootstrapsWorkforce, ReadsWorkforceChanges
{
    public function __construct(
        private Container $container,
        private NativePeopleWorkforceMapper $mapper,
    ) {}

    public function bootstrap(WorkforcePageRequest $request): WorkforcePage
    {
        try {
            $page = $this->container->make(ReadsNativeWorkforceBootstrap::class)->read(
                new NativeBootstrapRequest($request->pageCursor, $request->limit),
            );

            return $this->mapper->page($page);
        } catch (InvalidWorkforceBootstrapCursorException $exception) {
            throw new ProviderValidationException(
                providerId: NativePeopleAdapter::ID,
                operation: 'workforce.bootstrap',
                message: 'The native People bootstrap cursor was rejected.',
                previous: $exception,
            );
        } catch (BindingResolutionException|WorkforceProjectionException|\TypeError|\UnexpectedValueException|\ValueError $exception) {
            throw $this->compatibilityFailure('workforce.bootstrap', $exception);
        }
    }

    public function changes(WorkforceChangeRequest $request): WorkforceChangePage
    {
        try {
            $page = $this->container->make(ReadsNativeWorkforceChanges::class)->read(
                new NativeChangeRequest($request->resumeCursor, $request->pageCursor, $request->limit),
            );

            $changes = array_map(
                fn (NativeUpsert|NativeDeactivation $change) => $change instanceof NativeUpsert
                    ? $this->mapper->upsert($change)
                    : $this->mapper->deactivation($change),
                $page->changes,
            );

            return new WorkforceChangePage(
                changes: $changes,
                asOf: $page->asOf,
                nextPageCursor: $page->nextPageCursor,
                resumeCursor: $page->resumeCursor,
                complete: $page->complete,
            );
        } catch (InvalidWorkforceChangeCursorException $exception) {
            throw new ProviderValidationException(
                providerId: NativePeopleAdapter::ID,
                operation: 'workforce.changes',
                message: 'The native People change cursor was rejected.',
                previous: $exception,
            );
        } catch (BindingResolutionException|WorkforceProjectionException|\TypeError|\UnexpectedValueException|\ValueError $exception) {
            throw $this->compatibilityFailure('workforce.changes', $exception);
        }
    }

    private function compatibilityFailure(string $operation, \Throwable $exception): ProviderCompatibilityException
    {
        return new ProviderCompatibilityException(
            providerId: NativePeopleAdapter::ID,
            operation: $operation,
            message: 'The native People projection does not match the supported adapter contract.',
            previous: $exception,
        );
    }
}
