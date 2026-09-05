<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantContextMissingException;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforceChanges as ReadsPeopleChanges;
use App\Domains\People\Provider\Data\ExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceChangePage;
use App\Domains\People\Provider\Data\WorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforceOrganizationUnit;
use App\Domains\People\Provider\Data\WorkforceUpsert;
use App\Domains\People\Provider\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\FirstPartyPeople\Exceptions\ForeignProviderReferenceException;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceBootstrapPort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceChangePort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceRecordTranslator;

test('both first-party read boundaries refuse foreign nested references without leaking record identifiers', function (
    string $operation,
    string $recordKind,
    string $field,
    WorkforceResourceType $referenceType,
): void {
    $at = new DateTimeImmutable('2026-03-05T11:00:00Z');
    $company = new ExternalReference(WorkforceResourceType::Company, 'company:7');
    $foreign = new ExternalReference($referenceType, 'private-external-id', 'foreign-provider');
    $record = $recordKind === 'unit'
        ? new WorkforceOrganizationUnit(
            reference: new ExternalReference(WorkforceResourceType::OrganizationUnit, 'unit:4'),
            companyReference: $foreign,
            name: 'Engineering',
            active: true,
            effectiveAt: $at,
            observedAt: $at,
        )
        : new WorkforceEmployee(...array_replace([
            'reference' => new ExternalReference(WorkforceResourceType::Employee, 'employee:13'),
            'companyReference' => $company,
            'displayName' => 'Worker',
            'active' => true,
            'effectiveAt' => $at,
            'observedAt' => $at,
        ], [$field => $foreign]));

    $translator = new WorkforceRecordTranslator;
    if ($operation === 'bootstrap') {
        $reader = Mockery::mock(ReadsWorkforceBootstrap::class);
        $reader->shouldReceive('read')->once()->andReturn(new WorkforceBootstrapPage(
            employees: $recordKind === 'employee' ? [$record] : [],
            companies: [],
            asOf: $at,
            nextPageCursor: null,
            resumeCursor: 'resume',
            complete: true,
            organizationUnits: $recordKind === 'unit' ? [$record] : [],
        ));
        $invoke = fn () => (new WorkforceBootstrapPort($reader, $translator))->bootstrap(new WorkforcePageRequest);
    } else {
        $reader = Mockery::mock(ReadsPeopleChanges::class);
        $reader->shouldReceive('read')->once()->andReturn(new WorkforceChangePage(
            changes: [new WorkforceUpsert($record, $at)],
            since: $at,
            asOf: $at,
            nextPageCursor: null,
            resumeCursor: 'resume',
            complete: true,
        ));
        $invoke = fn () => (new WorkforceChangePort($reader, $translator))->changes(new WorkforceChangeRequest('resume'));
    }

    try {
        $invoke();
        $this->fail('A foreign nested reference must refuse the entire page.');
    } catch (ProviderValidationException $exception) {
        expect($exception->providerId)->toBe('blb-people')
            ->and($exception->operation)->toBe($operation === 'bootstrap' ? 'bootstrap_workforce' : 'read_workforce_changes')
            ->and($exception->context)->toBe(['published_provider_id' => 'foreign-provider'])
            ->and($exception->getPrevious())->toBeInstanceOf(ForeignProviderReferenceException::class)
            ->and($exception->getMessage())->not->toContain('private-external-id')
            ->and(json_encode($exception->context))->not->toContain('private-external-id');
    }
})->with(['bootstrap', 'changes'])->with([
    'unit company' => ['unit', 'companyReference', WorkforceResourceType::Company],
    'employee company' => ['employee', 'companyReference', WorkforceResourceType::Company],
    'employee user' => ['employee', 'userReference', WorkforceResourceType::User],
    'employee organization' => ['employee', 'organizationReference', WorkforceResourceType::OrganizationUnit],
    'employee manager' => ['employee', 'managerReference', WorkforceResourceType::Employee],
    'employee department head' => ['employee', 'departmentHeadReference', WorkforceResourceType::Employee],
]);

test('unexpected People reader failures retain their original exception at both boundaries', function (string $operation): void {
    $failure = new LogicException('Unexpected provider defect');
    $translator = new WorkforceRecordTranslator;
    $reader = Mockery::mock($operation === 'bootstrap' ? ReadsWorkforceBootstrap::class : ReadsPeopleChanges::class);
    $reader->shouldReceive('read')->once()->andThrow($failure);

    try {
        if ($operation === 'bootstrap') {
            (new WorkforceBootstrapPort($reader, $translator))->bootstrap(new WorkforcePageRequest);
        } else {
            (new WorkforceChangePort($reader, $translator))->changes(new WorkforceChangeRequest('resume'));
        }
        $this->fail('An unexpected reader failure must propagate.');
    } catch (Throwable $exception) {
        expect($exception)->toBe($failure);
    }
})->with(['bootstrap', 'changes']);

test('an incremental read without a tenant preserves the People tenant refusal', function (): void {
    app(TenantContext::class)->clear();

    expect(fn () => app(WorkforceChangePort::class)->changes(new WorkforceChangeRequest('resume')))
        ->toThrow(TenantContextMissingException::class);
});
