<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Contracts\ProviderAdapter;
use App\Domains\PeopleConnector\Connector\Contracts\ReadsWorkforceChanges;
use App\Domains\PeopleConnector\Connector\Contracts\ResolvesProviderPorts;
use App\Domains\PeopleConnector\Connector\Data\CapabilityChannel;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilitySet;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderDescriptor;
use App\Domains\PeopleConnector\Connector\Data\ProviderHealth;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforceChangeRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceDeactivation;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePageRequest;
use App\Domains\PeopleConnector\Connector\Data\WorkforceUpsert;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDelivery;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\CorruptWorkforcePageException;
use App\Domains\PeopleConnector\Connector\Exceptions\WorkforceSyncException;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\SyncCheckpoint;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceSyncRunner;
use App\Domains\PeopleConnector\Connector\Support\WorkforcePageChecksum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Feed page checksum (#204): a declared checksum that does not fingerprint the
 * page's content refuses the page before projection, raises a hash-free
 * sync_page_corrupt issue, and leaves the checkpoint where it was.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const PAGE_SUM_PROVIDER = 'test.page-checksum';

function pageSumRef(WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference(PAGE_SUM_PROVIDER, $type, $id);
}

function pageSumCompany(DateTimeImmutable $at): WorkforceCompany
{
    return new WorkforceCompany(pageSumRef(WorkforceResourceType::Company, 'co-1'), 'Checksum Co', true, $at, code: 'CO-1');
}

function pageSumEmployee(string $id, string $name, DateTimeImmutable $at): WorkforceEmployee
{
    return new WorkforceEmployee(
        pageSumRef(WorkforceResourceType::Employee, $id),
        pageSumRef(WorkforceResourceType::Company, 'co-1'),
        $name,
        true,
        $at,
        $at,
        employeeNumber: strtoupper($id),
        email: strtolower($id).'@example.test',
    );
}

/** @return array{int, int, Actor} [tenantId, connectionId, actor] */
function pageSumTenant(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    app(TenantContext::class)->set((int) $tenant->id);
    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company((int) $company->id), PAGE_SUM_PROVIDER);
    $store->activate((int) $connection->id);

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

    return [(int) $tenant->id, (int) $connection->id, new Actor(PrincipalType::USER, 21, (int) $company->id, tenantId: (int) $tenant->id)];
}

/**
 * @param  array<string, WorkforcePage>  $bootstrapPages
 * @param  array<string, WorkforceChangePage>  $changePages
 */
function pageSumProvider(array $bootstrapPages = [], array $changePages = []): ProviderAdapter
{
    return new class($bootstrapPages, $changePages) implements ProviderAdapter, ResolvesProviderPorts
    {
        public object $source;

        public function __construct(array $bootstrapPages, array $changePages)
        {
            $this->source = new class($bootstrapPages, $changePages) implements BootstrapsWorkforce, ReadsWorkforceChanges
            {
                public function __construct(private array $bootstrapPages, private array $changePages) {}

                public function bootstrap(WorkforcePageRequest $request): WorkforcePage
                {
                    return $this->bootstrapPages[$request->pageCursor ?? 'first'] ?? throw new LogicException('No scripted bootstrap page.');
                }

                public function changes(WorkforceChangeRequest $request): WorkforceChangePage
                {
                    return $this->changePages[$request->pageCursor ?? 'first'] ?? throw new LogicException('No scripted change page.');
                }
            };
        }

        public function descriptor(): ProviderDescriptor
        {
            return new ProviderDescriptor(PAGE_SUM_PROVIDER, 'Page Checksum Provider', '0.1.0', '1.0.0');
        }

        public function capabilities(): CapabilitySet
        {
            return new CapabilitySet([
                new CapabilityDeclaration(PeopleCapability::EmployeeDirectory, [
                    new CapabilityChannel(CapabilityDelivery::Synchronous, BootstrapsWorkforce::class),
                    new CapabilityChannel(CapabilityDelivery::Synchronous, ReadsWorkforceChanges::class),
                ]),
            ]);
        }

        public function health(): ProviderHealth
        {
            return new ProviderHealth(ProviderHealthState::Healthy, new DateTimeImmutable('2026-09-01T00:00:00+00:00'));
        }

        public function resolvePort(string $contract, ProviderPortAuthorization $authorization): ?object
        {
            return $this->source instanceof $contract ? $this->source : null;
        }
    };
}

function pageSumBootstrapPage(DateTimeImmutable $at, ?string $checksum = null, bool $declareTrue = false): WorkforcePage
{
    $page = new WorkforcePage(
        [pageSumEmployee('emp-1', 'Mona Manager', $at)],
        $at,
        resumeCursor: 'resume-after-bootstrap',
        complete: true,
        companies: [pageSumCompany($at)],
    );

    return $checksum === null && ! $declareTrue ? $page : new WorkforcePage(
        $page->employees, $page->asOf, resumeCursor: $page->resumeCursor, complete: true, companies: $page->companies,
        checksum: $declareTrue ? WorkforcePageChecksum::of($page) : $checksum,
    );
}

function pageSumChangePage(DateTimeImmutable $at, ?string $checksum = null, bool $declareTrue = false): WorkforceChangePage
{
    $page = new WorkforceChangePage([
        new WorkforceUpsert(pageSumEmployee('emp-2', 'Rae Report', $at), $at),
        new WorkforceDeactivation(pageSumRef(WorkforceResourceType::Employee, 'emp-1'), $at),
    ], $at, resumeCursor: 'resume-after-change', complete: true);

    return $checksum === null && ! $declareTrue ? $page : new WorkforceChangePage(
        $page->changes, $page->asOf, resumeCursor: $page->resumeCursor, complete: true,
        checksum: $declareTrue ? WorkforcePageChecksum::of($page) : $checksum,
    );
}

/** Projections are company-owned; the tests count the whole tenant on purpose. */
function pageSumEmployees(int $tenantId): Builder
{
    return WorkforceEmployeeProjection::query()->withoutCompanyScope('test counts the whole tenant')->forTenant($tenantId);
}

function pageSumIssues(int $tenantId, int $connectionId): Collection
{
    return ReconciliationIssue::query()->forTenant($tenantId)->where('connection_id', $connectionId)->orderBy('id')->get();
}

test('the fingerprint is deterministic over the records and blind to cursors and instants of reach', function (): void {
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $a = new WorkforcePage([pageSumEmployee('emp-1', 'Mona Manager', $at)], $at, nextPageCursor: 'page-2', companies: [pageSumCompany($at)]);
    $b = new WorkforcePage([pageSumEmployee('emp-1', 'Mona Manager', $at)], $at->modify('+1 hour'), resumeCursor: 'done', complete: true, companies: [pageSumCompany($at)]);
    $renamed = new WorkforcePage([pageSumEmployee('emp-1', 'Mona Manageress', $at)], $at, nextPageCursor: 'page-2', companies: [pageSumCompany($at)]);
    $reordered = new WorkforceChangePage(array_reverse(pageSumChangePage($at)->changes), $at, resumeCursor: 'r', complete: true);

    expect(WorkforcePageChecksum::of($a))->toBe(WorkforcePageChecksum::of($b))
        ->and(WorkforcePageChecksum::isWellFormed(WorkforcePageChecksum::of($a)))->toBeTrue()
        ->and(WorkforcePageChecksum::of($renamed))->not->toBe(WorkforcePageChecksum::of($a))
        ->and(WorkforcePageChecksum::of($reordered))->not->toBe(WorkforcePageChecksum::of(pageSumChangePage($at)));
});

test('a declared checksum must be a lowercase hex SHA-256', function (string $bad): void {
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');

    expect(fn () => new WorkforcePage([], $at, resumeCursor: 'r', complete: true, checksum: $bad))->toThrow(InvalidArgumentException::class, 'lowercase hex SHA-256')
        ->and(fn () => new WorkforceChangePage([], $at, resumeCursor: 'r', complete: true, checksum: $bad))->toThrow(InvalidArgumentException::class, 'lowercase hex SHA-256');
})->with(['', 'abc', strtoupper(str_repeat('a', 64)), str_repeat('g', 64), 'sha256:'.str_repeat('a', 64)]);

test('a bootstrap page whose declared checksum matches is projected and checkpointed as usual', function (): void {
    [$tenantId, $connectionId, $actor] = pageSumTenant('Checksum Bootstrap OK Tenant');
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');

    $report = app(WorkforceSyncRunner::class)->bootstrap($actor, pageSumProvider(['first' => pageSumBootstrapPage($at, declareTrue: true)]), $connectionId);

    expect($report->employees)->toBe(1)
        ->and($report->checkpointAdvanced)->toBeTrue()
        ->and(pageSumEmployees($tenantId)->count())->toBe(1)
        ->and(pageSumIssues($tenantId, $connectionId))->toHaveCount(0);
});

test('a bootstrap page whose declared checksum does not match is refused before projection, reported, and leaves no checkpoint', function (): void {
    [$tenantId, $connectionId, $actor] = pageSumTenant('Checksum Bootstrap Corrupt Tenant');
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');

    expect(fn () => app(WorkforceSyncRunner::class)->bootstrap($actor, pageSumProvider(['first' => pageSumBootstrapPage($at, checksum: str_repeat('0', 64))]), $connectionId))
        ->toThrow(CorruptWorkforcePageException::class, 'refused before projection');

    $issues = pageSumIssues($tenantId, $connectionId);
    expect(pageSumEmployees($tenantId)->count())->toBe(0)
        ->and(SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->count())->toBe(0)
        ->and($issues)->toHaveCount(1)
        ->and($issues[0]->kind)->toBe(WorkforceSyncRunner::ISSUE_KIND_PAGE_CORRUPT)
        ->and($issues[0]->issue_key)->toBe('sync:page:corrupt:bootstrap:first')
        ->and($issues[0]->severity)->toBe('error')
        ->and($issues[0]->details['reason_code'] ?? null)->toBe('checksum_mismatch');
});

test('an incremental page whose declared checksum does not match is refused before projection and the checkpoint does not advance', function (): void {
    [$tenantId, $connectionId, $actor] = pageSumTenant('Checksum Incremental Corrupt Tenant');
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $later = new DateTimeImmutable('2026-09-02T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, pageSumProvider(['first' => pageSumBootstrapPage($at)]), $connectionId);
    $before = SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->sole();

    expect(fn () => $runner->incremental($actor, pageSumProvider(changePages: ['first' => pageSumChangePage($later, checksum: str_repeat('f', 64))]), $connectionId))
        ->toThrow(CorruptWorkforcePageException::class);

    $after = SyncCheckpoint::query()->forTenant($tenantId)->where('connection_id', $connectionId)->sole();
    $issues = pageSumIssues($tenantId, $connectionId);

    // Nothing of the page landed: emp-2 was never projected and emp-1 is still active.
    expect(pageSumEmployees($tenantId)->count())->toBe(1)
        ->and(pageSumEmployees($tenantId)->sole()->active)->toBeTrue()
        ->and($after->version)->toBe($before->version)
        ->and($after->resume_cursor)->toBe('resume-after-bootstrap')
        ->and($issues->pluck('kind')->all())->toBe([WorkforceSyncRunner::ISSUE_KIND_PAGE_CORRUPT])
        ->and($issues[0]->issue_key)->toBe('sync:page:corrupt:incremental:first');

    // The next pass asks for the same page; an intact copy lands normally.
    $report = $runner->incremental($actor, pageSumProvider(changePages: ['first' => pageSumChangePage($later, declareTrue: true)]), $connectionId);
    expect($report->checkpointAdvanced)->toBeTrue()
        ->and(pageSumEmployees($tenantId)->count())->toBe(2);
});

test('a page with no declared checksum is processed as before', function (): void {
    [$tenantId, $connectionId, $actor] = pageSumTenant('Checksum Undeclared Tenant');
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $runner = app(WorkforceSyncRunner::class);
    $runner->bootstrap($actor, pageSumProvider(['first' => pageSumBootstrapPage($at)]), $connectionId);
    $report = $runner->incremental($actor, pageSumProvider(changePages: ['first' => pageSumChangePage($at->modify('+1 day'))]), $connectionId);

    expect($report->checkpointAdvanced)->toBeTrue()
        ->and(pageSumIssues($tenantId, $connectionId)->where('kind', WorkforceSyncRunner::ISSUE_KIND_PAGE_CORRUPT))->toHaveCount(0);
});

test('the corrupt-page issue carries nothing of the page: no identifier, name, email or hash', function (): void {
    [$tenantId, $connectionId, $actor] = pageSumTenant('Checksum Redaction Tenant');
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $declared = str_repeat('a', 64);

    try {
        app(WorkforceSyncRunner::class)->bootstrap($actor, pageSumProvider(['first' => pageSumBootstrapPage($at, checksum: $declared)]), $connectionId);
    } catch (WorkforceSyncException) {
    }

    $issue = pageSumIssues($tenantId, $connectionId)->sole();
    $row = json_encode($issue->getAttributes(), JSON_UNESCAPED_SLASHES);

    expect($issue->external_id)->toBeNull()
        ->and($issue->resource_type)->toBeNull()
        ->and(array_keys($issue->details))->toEqualCanonicalizing(['field', 'reason_code'])
        ->and($row)->not->toContain('emp-1')->not->toContain('EMP-1')->not->toContain('Mona')->not->toContain('example.test')->not->toContain('Checksum Co')
        ->and($row)->not->toContain($declared)->not->toContain(WorkforcePageChecksum::of(pageSumBootstrapPage($at)));
});
