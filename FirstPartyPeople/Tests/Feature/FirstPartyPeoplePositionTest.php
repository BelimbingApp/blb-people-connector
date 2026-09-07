<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Domains\People\Provider\Contracts\ReadsWorkforceBootstrap;
use App\Domains\People\Provider\Contracts\ReadsWorkforcePositions;
use App\Domains\People\Provider\Data\ExternalReference as PeopleExternalReference;
use App\Domains\People\Provider\Data\WorkforceBootstrapPage;
use App\Domains\People\Provider\Data\WorkforceBootstrapRequest;
use App\Domains\People\Provider\Data\WorkforceCompany as PeopleWorkforceCompany;
use App\Domains\People\Provider\Data\WorkforceEmployee as PeopleWorkforceEmployee;
use App\Domains\People\Provider\Data\WorkforcePosition as PeopleWorkforcePosition;
use App\Domains\People\Provider\Enums\WorkforceResourceType as PeopleWorkforceResourceType;
use App\Domains\People\Settings\Models\PeopleReferenceEntry;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePosition;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderValidationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\SchedulerPrincipal;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use App\Domains\PeopleConnector\FirstPartyPeople\Exceptions\ForeignProviderReferenceException;
use App\Domains\PeopleConnector\FirstPartyPeople\FirstPartyPeopleAdapter;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceBootstrapPort;
use App\Domains\PeopleConnector\FirstPartyPeople\Services\WorkforceRecordTranslator;
use Illuminate\Support\Collection;

/*
 * Self-contained: every helper here is prefixed fpPosition. The only outside
 * helper is the platform's createTenantWithCompany().
 */

afterEach(fn () => app(TenantContext::class)->clear());

test('a bootstrap page carries the positions People publishes for each company it names', function (): void {
    $at = fpPositionAt();
    $company = fpPositionPeopleRef(PeopleWorkforceResourceType::Company, '7');
    $unit = fpPositionPeopleRef(PeopleWorkforceResourceType::OrganizationUnit, '3');
    $page = fpPositionPage($at, companies: [fpPositionPeopleCompany($company, $at)]);
    $published = [
        new PeopleWorkforcePosition(fpPositionPeopleRef(PeopleWorkforceResourceType::Position, '41'), $company, 'Engineer', true, $at, $unit),
        new PeopleWorkforcePosition(fpPositionPeopleRef(PeopleWorkforceResourceType::Position, '42'), $company, 'Analyst', true, $at),
    ];

    $with = fpPositionPort($page, ['7' => $published])->bootstrap(new WorkforcePageRequest);
    $without = fpPositionPort($page, ['7' => []])->bootstrap(new WorkforcePageRequest);

    expect($with->positions)->toHaveCount(2)
        ->and($with->positions[0])->toBeInstanceOf(WorkforcePosition::class)
        ->and($with->positions[0]->reference->providerId)->toBe('blb-people')
        ->and($with->positions[0]->reference->resourceType)->toBe(WorkforceResourceType::Position)
        ->and($with->positions[0]->reference->externalId)->toBe('41')
        ->and($with->positions[0]->companyReference->providerId)->toBe('blb-people')
        ->and($with->positions[0]->companyReference->externalId)->toBe('7')
        ->and($with->positions[0]->organizationReference->externalId)->toBe('3')
        ->and($with->positions[0]->name)->toBe('Engineer')
        ->and($with->positions[0]->active)->toBeTrue()
        ->and($with->positions[0]->effectiveAt)->toEqual($at)
        ->and($with->positions[0]->observedAt)->toEqual($at)
        ->and($with->positions[0]->code)->toBeNull()
        ->and($with->positions[0]->tier)->toBeNull()
        ->and($with->positions[1]->reference->externalId)->toBe('42')
        ->and($with->positions[1]->organizationReference)->toBeNull()
        ->and($without->positions)->toBe([])
        ->and($with->checksum)->not->toBeNull()
        ->and($with->checksum)->not->toBe($without->checksum);
});

test('a People position published under a foreign provider id is refused, not relabelled', function (): void {
    $at = fpPositionAt();
    $company = fpPositionPeopleRef(PeopleWorkforceResourceType::Company, '7');
    $page = fpPositionPage($at, companies: [fpPositionPeopleCompany($company, $at)]);
    $foreign = new PeopleWorkforcePosition(
        new PeopleExternalReference(PeopleWorkforceResourceType::Position, 'private-position-id', 'hr2000.sbg'),
        $company,
        'Foreign Engineer',
        true,
        $at,
    );

    try {
        fpPositionPort($page, ['7' => [$foreign]])->bootstrap(new WorkforcePageRequest);
        $this->fail('A foreign position must refuse the page.');
    } catch (ProviderValidationException $exception) {
        expect($exception->providerId)->toBe('blb-people')
            ->and($exception->operation)->toBe('bootstrap_workforce')
            ->and($exception->context)->toBe(['published_provider_id' => 'hr2000.sbg'])
            ->and($exception->getPrevious())->toBeInstanceOf(ForeignProviderReferenceException::class)
            ->and($exception->getMessage())->not->toContain('private-position-id');
    }
});

test('an employee whose People record names a position arrives with that reference, and one without arrives null', function (): void {
    $at = fpPositionAt();
    $company = fpPositionPeopleRef(PeopleWorkforceResourceType::Company, '7');
    $page = fpPositionPage($at, employees: [
        fpPositionPeopleEmployee('11', $company, $at, fpPositionPeopleRef(PeopleWorkforceResourceType::Position, '41')),
        fpPositionPeopleEmployee('12', $company, $at, null),
    ]);

    $translated = fpPositionPort($page, [])->bootstrap(new WorkforcePageRequest);

    expect($translated->employees[0]->positionReference?->providerId)->toBe('blb-people')
        ->and($translated->employees[0]->positionReference?->resourceType)->toBe(WorkforceResourceType::Position)
        ->and($translated->employees[0]->positionReference?->externalId)->toBe('41')
        ->and($translated->employees[1]->positionReference)->toBeNull();
});

test('a full bootstrap projects exactly the positions People publishes and re-running it creates no duplicates', function (): void {
    [$tenant, $company] = createTenantWithCompany(['name' => 'Position Tenant']);
    $sibling = Company::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Sibling Company']);
    [, $otherCompany] = createTenantWithCompany(['name' => 'Other Position Tenant']);
    $unit = fpPositionEntry($company, PeopleReferenceEntry::TYPE_ORGANIZATION_UNIT, 'OPS', 'Operations');
    fpPositionEntry($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'ENG', 'Engineer', parent: $unit);
    fpPositionEntry($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'ANA', 'Analyst');
    fpPositionEntry($company, PeopleReferenceEntry::TYPE_JOB_TITLE, 'OLD', 'Retired Role', status: PeopleReferenceEntry::STATUS_INACTIVE);
    fpPositionEntry($sibling, PeopleReferenceEntry::TYPE_JOB_TITLE, 'SIB', 'Sibling Engineer');
    fpPositionEntry($otherCompany, PeopleReferenceEntry::TYPE_JOB_TITLE, 'OTH', 'Other Tenant Engineer');
    app(TenantContext::class)->set((int) $tenant->id);
    fpPositionAllowEverything();
    $store = app(ProviderConnectionStore::class);
    $connection = $store->activate((int) $store->configure(ProviderScope::company((int) $company->id), FirstPartyPeopleAdapter::ID)->id);
    $actor = app(SchedulerPrincipal::class)->forConnection($connection);
    $adapter = app(FirstPartyPeopleAdapter::class);

    $first = app(WorkforceSyncRunner::class)->bootstrap($actor, $adapter, (int) $connection->id);
    $published = app(ReadsWorkforcePositions::class)->positions((string) $company->id);
    $rows = fpPositionRows((int) $tenant->id);
    $companyEntityId = fpPositionCompanyEntityId((int) $tenant->id, (int) $company->id);
    $siblingEntityId = fpPositionCompanyEntityId((int) $tenant->id, (int) $sibling->id);
    $scoped = $rows->where('company_entity_id', $companyEntityId);

    // People's bootstrap page is tenant-wide: it carries every company of the
    // tenant whatever the connection's scope, and its positions travel with
    // it under the same rule, so a sibling company's positions are projected
    // exactly when its company is. Another tenant is never on the page.
    expect($published)->toHaveCount(2)
        ->and($scoped->pluck('name')->sort()->values()->all())->toBe(['Analyst', 'Engineer'])
        ->and($scoped->firstWhere('name', 'Engineer')->organization_entity_id)->not->toBeNull()
        ->and($scoped->firstWhere('name', 'Analyst')->organization_entity_id)->toBeNull()
        ->and($rows->pluck('name')->all())->not->toContain('Retired Role')
        ->and($rows->pluck('name')->all())->not->toContain('Other Tenant Engineer')
        ->and($rows->where('company_entity_id', $siblingEntityId)->pluck('name')->all())->toBe(['Sibling Engineer'])
        ->and($first->positions)->toBe(3)
        ->and(WorkforcePositionProjection::query()->withoutCompanyScope('Test: no row may exist outside the connection tenant.')->count())->toBe(3);

    $identities = fpPositionIdentityCount((int) $tenant->id);
    app(WorkforceSyncRunner::class)->bootstrap($actor, $adapter, (int) $connection->id);

    // The second pass re-observes the same facts: the store upserts in place,
    // so neither a projection row nor a position identity is added.
    expect(fpPositionRows((int) $tenant->id)->count())->toBe(3)
        ->and(fpPositionIdentityCount((int) $tenant->id))->toBe($identities)
        ->and($identities)->toBe(3);
});

function fpPositionAt(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-03-06T08:00:00.000000+00:00');
}

function fpPositionPeopleRef(PeopleWorkforceResourceType $type, string $id): PeopleExternalReference
{
    return new PeopleExternalReference($type, $id);
}

function fpPositionPeopleCompany(PeopleExternalReference $reference, DateTimeImmutable $at): PeopleWorkforceCompany
{
    return new PeopleWorkforceCompany(reference: $reference, name: 'Published Company', active: true, observedAt: $at);
}

function fpPositionPeopleEmployee(string $id, PeopleExternalReference $company, DateTimeImmutable $at, ?PeopleExternalReference $position): PeopleWorkforceEmployee
{
    return new PeopleWorkforceEmployee(
        reference: fpPositionPeopleRef(PeopleWorkforceResourceType::Employee, $id),
        companyReference: $company,
        displayName: 'Worker '.$id,
        active: true,
        effectiveAt: $at,
        observedAt: $at,
        positionReference: $position,
    );
}

/**
 * @param  list<PeopleWorkforceEmployee>  $employees
 * @param  list<PeopleWorkforceCompany>  $companies
 */
function fpPositionPage(DateTimeImmutable $at, array $employees = [], array $companies = []): WorkforceBootstrapPage
{
    return new WorkforceBootstrapPage(
        employees: $employees,
        companies: $companies,
        organizationUnits: [],
        asOf: $at,
        nextPageCursor: null,
        resumeCursor: 'resume',
        complete: true,
    );
}

/**
 * The port over a scripted People page and a scripted positions reader keyed
 * by company stable id; a company not in the script has published nothing.
 *
 * @param  array<string, list<PeopleWorkforcePosition>>  $positions
 */
function fpPositionPort(WorkforceBootstrapPage $page, array $positions): WorkforceBootstrapPort
{
    $reader = new class($page) implements ReadsWorkforceBootstrap
    {
        public function __construct(private readonly WorkforceBootstrapPage $page) {}

        public function read(WorkforceBootstrapRequest $request): WorkforceBootstrapPage
        {
            return $this->page;
        }
    };
    $reads = new class($positions) implements ReadsWorkforcePositions
    {
        public function __construct(private readonly array $positions) {}

        public function positions(string $companyStableId): array
        {
            return $this->positions[$companyStableId] ?? [];
        }
    };

    return new WorkforceBootstrapPort($reader, $reads, new WorkforceRecordTranslator);
}

function fpPositionEntry(
    Company $company,
    string $type,
    string $code,
    string $name,
    ?PeopleReferenceEntry $parent = null,
    string $status = PeopleReferenceEntry::STATUS_ACTIVE,
): PeopleReferenceEntry {
    return PeopleReferenceEntry::query()->create([
        'company_id' => $company->id,
        'parent_id' => $parent?->id,
        'type' => $type,
        'code' => $code,
        'name' => $name,
        'status' => $status,
    ]);
}

function fpPositionAllowEverything(): void
{
    app()->instance(AuthorizationService::class, new class implements AuthorizationService
    {
        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return AuthorizationDecision::allow();
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void {}

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    });
}

/** @return Collection<int, WorkforcePositionProjection> */
function fpPositionRows(int $tenantId): Collection
{
    return WorkforcePositionProjection::query()
        ->withoutCompanyScope('Test: the assertion is about which companies have rows at all.')
        ->forTenant($tenantId)
        ->get();
}

function fpPositionIdentityCount(int $tenantId): int
{
    return ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('resource_type', WorkforceResourceType::Position->value)
        ->count();
}

function fpPositionCompanyEntityId(int $tenantId, int $platformCompanyId): int
{
    return (int) ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('resource_type', WorkforceResourceType::Company->value)
        ->where('external_id', (string) $platformCompanyId)
        ->sole()
        ->workforce_entity_id;
}
