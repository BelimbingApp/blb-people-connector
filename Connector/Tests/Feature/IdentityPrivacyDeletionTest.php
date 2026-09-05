<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\PrivacyDeletionException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Services\PrivacyDeletionService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function identityErasureAuthz(bool $allow): void
{
    app()->instance(AuthorizationService::class, new class($allow) implements AuthorizationService
    {
        public function __construct(private bool $allow) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allow
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            if (! $this->allow) {
                throw new ProviderAuthorizationException(
                    providerId: 'connector',
                    operation: 'erase_identity',
                    message: 'The actor lacks the connector identity erasure capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/**
 * A tenant with one active company-scoped connection carrying one employee
 * identity, its projection, and the entity the two share.
 *
 * @return array{tenantId: int, companyId: int, connectionId: int, reference: ExternalReference, entityId: int, actor: Actor}
 */
function identityErasureFixture(): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => 'Identity Erasure Tenant']);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company($companyId), 'test.erasure');
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    $at = new DateTimeImmutable('2026-09-06T08:00:00+00:00');
    $companyReference = new ExternalReference('test.erasure', WorkforceResourceType::Company, 'ERASE-CO');
    $reference = new ExternalReference('test.erasure', WorkforceResourceType::Employee, 'ERASE-1');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($connectionId, new WorkforceCompany($companyReference, 'Erasure Co', true, $at));
    $projections->upsert($connectionId, new WorkforceEmployee(
        reference: $reference,
        companyReference: $companyReference,
        displayName: 'Ada Lovelace',
        active: true,
        effectiveAt: $at,
        observedAt: $at,
        employeeNumber: 'EMP-ERASE-1',
        email: 'ada@example.test',
    ));

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'connectionId' => $connectionId,
        'reference' => $reference,
        'entityId' => (int) app(WorkforceIdentityStore::class)->resolve($connectionId, $reference)->id,
        'actor' => new Actor(PrincipalType::USER, 4001, $companyId, tenantId: $tenantId),
    ];
}

function identityErasureProvenance(): WorkforceProvenance
{
    return new WorkforceProvenance('privacy.request', 'dsr-2026-09-06');
}

test('erasing an identity tombstones it and its projection while the workforce entity id stays stable', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);

    $entity = app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    );

    $projection = WorkforceEmployeeProjection::query()
        ->where('workforce_entity_id', $f['entityId'])
        ->firstOrFail();
    $identity = ExternalIdentity::query()
        ->where('connection_id', $f['connectionId'])
        ->where('workforce_entity_id', $f['entityId'])
        ->firstOrFail();

    expect((int) $entity->id)->toBe($f['entityId'])
        ->and($projection->privacy_deleted_at)->not->toBeNull()
        ->and($projection->display_name)->toBe('[redacted]')
        ->and($projection->email)->toBeNull()
        ->and($identity->state)->toBe(ExternalIdentity::STATE_INACTIVE);
});

test('erasing an identity writes an audit row naming the erased reference', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);

    app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    );

    $audit = DB::table('people_connector_connector_workforce_snapshots')
        ->where('workforce_entity_id', $f['entityId'])
        ->where('event_type', 'identity_erased')
        ->count();

    expect($audit)->toBe(1);
});

test('erasing an identity is refused when the actor lacks the operator capability', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(false);

    expect(fn () => app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    ))->toThrow(ProviderAuthorizationException::class);

    expect(WorkforceEmployeeProjection::query()
        ->where('workforce_entity_id', $f['entityId'])
        ->value('privacy_deleted_at'))->toBeNull();
});

test('erasing an identity is refused for an actor outside the connection tenant', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);
    $outsider = new Actor(PrincipalType::USER, 4002, $f['companyId'], tenantId: $f['tenantId'] + 1);

    expect(fn () => app(PrivacyDeletionService::class)->eraseIdentity(
        $outsider,
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    ))->toThrow(ProviderAuthorizationException::class);

    expect(WorkforceEmployeeProjection::query()
        ->where('workforce_entity_id', $f['entityId'])
        ->value('privacy_deleted_at'))->toBeNull();
});

test('erasing an identity is refused while an open reconciliation issue still references it', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);
    app(ReconciliationIssueStore::class)->report(
        $f['connectionId'],
        'sync:employee:ERASE-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'ERASE-1',
    );

    expect(fn () => app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    ))->toThrow(PrivacyDeletionException::class);

    expect(WorkforceEmployeeProjection::query()
        ->where('workforce_entity_id', $f['entityId'])
        ->value('privacy_deleted_at'))->toBeNull();
});

test('erasing an identity resolved by a closed reconciliation issue is allowed', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);
    $issue = app(ReconciliationIssueStore::class)->report(
        $f['connectionId'],
        'sync:employee:ERASE-1',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'ERASE-1',
    );
    app(ReconciliationIssueStore::class)->resolve((int) $issue->id);

    $entity = app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    );

    expect((int) $entity->id)->toBe($f['entityId']);
});

test('erasing an identity writes to no table the connector does not own', function (): void {
    $f = identityErasureFixture();
    identityErasureAuthz(true);
    $written = [];
    DB::listen(function ($query) use (&$written): void {
        if (preg_match('/^\s*(insert into|update|delete from)\s+"?([a-z0-9_]+)"?/i', $query->sql, $m) === 1) {
            $written[] = strtolower($m[2]);
        }
    });

    app(PrivacyDeletionService::class)->eraseIdentity(
        $f['actor'],
        $f['connectionId'],
        $f['reference'],
        identityErasureProvenance(),
    );

    // People owns the business records this entity is referenced by. Erasure is
    // a connector-side tombstone; a write outside the connector's own tables
    // would be erasing People history, which this lane must never do.
    $foreign = array_values(array_unique(array_filter(
        $written,
        static fn (string $table): bool => ! str_starts_with($table, 'people_connector_'),
    )));

    expect($written)->not->toBeEmpty()
        ->and($foreign)->toBe([]);
});
