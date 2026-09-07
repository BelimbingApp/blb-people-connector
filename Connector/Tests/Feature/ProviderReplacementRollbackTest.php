<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderReplacementException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Self-contained: every helper is prefixed rollback and lives here, so the file
 * passes or fails alone for its own reasons. The only outside helper is the
 * platform's createTenantWithCompany().
 */

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const ROLLBACK_OLD_PROVIDER = 'test.replaced';

const ROLLBACK_NEW_PROVIDER = 'test.replacement';

const ROLLBACK_REMAP_AT = '2026-09-05T09:00:00+00:00';

function rollbackAuthz(bool $allow): void
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
                    operation: 'replace_provider',
                    message: 'The actor lacks the connector identity management capability.',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

function rollbackRef(string $providerId, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($providerId, $type, $id);
}

/**
 * A tenant whose company-scoped connection has been replaced: the old provider
 * holds two employees and a company, the new connection has been activated
 * (switching the old one off), and nothing has been remapped yet.
 *
 * @return array{tenantId: int, companyId: int, oldConnectionId: int, newConnectionId: int, entityIds: array<string, int>, actor: Actor}
 */
function rollbackFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company((int) $company->id);

    $old = $store->configure($scope, ROLLBACK_OLD_PROVIDER);
    $oldConnectionId = (int) $store->activate((int) $old->id)->id;

    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $projections = app(WorkforceProjectionStore::class);
    $companyRef = rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Company, 'OLD-CO');
    $projections->upsert($oldConnectionId, new WorkforceCompany($companyRef, 'Replaced Co', true, $at));

    foreach (['OLD-EMP-1' => 'Ada Old', 'OLD-EMP-2' => 'Bo Old'] as $externalId => $displayName) {
        $projections->upsert($oldConnectionId, new WorkforceEmployee(
            reference: rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Employee, $externalId),
            companyReference: $companyRef,
            displayName: $displayName,
            active: true,
            effectiveAt: $at,
            observedAt: $at,
        ));
    }

    $identities = app(WorkforceIdentityStore::class);
    $entityIds = [];
    foreach (['OLD-EMP-1', 'OLD-EMP-2'] as $externalId) {
        $entityIds[$externalId] = (int) $identities->resolve(
            $oldConnectionId,
            rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Employee, $externalId),
        )->id;
    }

    $new = $store->configure($scope, ROLLBACK_NEW_PROVIDER);
    $newConnectionId = (int) $store->activate((int) $new->id)->id;

    rollbackAuthz(true);

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'oldConnectionId' => $oldConnectionId,
        'newConnectionId' => $newConnectionId,
        'entityIds' => $entityIds,
        'actor' => new Actor(PrincipalType::USER, 7001, (int) $company->id, tenantId: $tenantId),
    ];
}

function rollbackMapping(string $from, string $to): ProviderIdentityMapping
{
    return new ProviderIdentityMapping(
        rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Employee, $from),
        rollbackRef(ROLLBACK_NEW_PROVIDER, WorkforceResourceType::Employee, $to),
    );
}

/** Remap both employees at ROLLBACK_REMAP_AT and return the audit id the remap wrote. */
function rollbackRemap(array $f): int
{
    app(ProviderReplacementService::class)->remap(
        $f['actor'],
        $f['oldConnectionId'],
        $f['newConnectionId'],
        [rollbackMapping('OLD-EMP-1', 'NEW-EMP-1'), rollbackMapping('OLD-EMP-2', 'NEW-EMP-2')],
        'replacement-2026-09-05',
        new DateTimeImmutable(ROLLBACK_REMAP_AT),
    );

    return (int) OperatorAudit::query()
        ->forTenant($f['tenantId'])
        ->where('operation', OperatorAuditOperation::IdentitiesRemapped->value)
        ->orderByDesc('id')
        ->firstOrFail()
        ->id;
}

function rollbackIdentity(int $tenantId, int $connectionId, string $externalId): ?ExternalIdentity
{
    return ExternalIdentity::query()
        ->forTenant($tenantId)
        ->where('connection_id', $connectionId)
        ->where('external_id', $externalId)
        ->first();
}

/** @return array<int, array<string, mixed>> every identity row in the tenant, keyed by id, as stored */
function rollbackRows(int $tenantId): array
{
    return DB::table('people_connector_connector_external_identities')
        ->where('tenant_id', $tenantId)
        ->orderBy('id')
        ->get()
        ->map(fn (object $row): array => (array) $row)
        ->keyBy('id')
        ->all();
}

function rollbackAudits(int $tenantId): int
{
    return OperatorAudit::query()
        ->forTenant($tenantId)
        ->where('operation', OperatorAuditOperation::IdentitiesRemapRolledBack->value)
        ->count();
}

/** A later sync pass on the replacement connection observes one handed-over identity again. */
function rollbackObserve(array $f, string $externalId, string $at): void
{
    app(WorkforceIdentityStore::class)->resolveOrCreateIdentity(
        $f['newConnectionId'],
        rollbackRef(ROLLBACK_NEW_PROVIDER, WorkforceResourceType::Employee, $externalId),
        new DateTimeImmutable($at),
    );
}

test('a remap rolled back restores both old identities and leaves the new ones remapped, entities unchanged', function (): void {
    $f = rollbackFixture('Rollback Happy Tenant');
    $rowsBefore = count(rollbackRows($f['tenantId']));
    $auditId = rollbackRemap($f);
    expect(count(rollbackRows($f['tenantId'])))->toBe($rowsBefore + 2);

    $report = app(ProviderReplacementService::class)->rollback($f['actor'], $auditId, 'rollback-2026-09-06');

    $identities = app(WorkforceIdentityStore::class);
    $old1 = rollbackIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1');
    $old2 = rollbackIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-2');
    $new1 = rollbackIdentity($f['tenantId'], $f['newConnectionId'], 'NEW-EMP-1');
    $new2 = rollbackIdentity($f['tenantId'], $f['newConnectionId'], 'NEW-EMP-2');

    expect($report->remapped)->toBe(2)
        ->and($report->fromConnectionId)->toBe($f['newConnectionId'])
        ->and($report->toConnectionId)->toBe($f['oldConnectionId'])
        ->and($old1?->state)->toBe(ExternalIdentity::STATE_ACTIVE)
        ->and($old2?->state)->toBe(ExternalIdentity::STATE_ACTIVE)
        ->and($old1?->replaced_by_identity_id)->toBeNull()
        ->and($old2?->replaced_by_identity_id)->toBeNull()
        ->and($old1?->effective_to)->toBeNull()
        ->and($new1?->state)->toBe(ExternalIdentity::STATE_REMAPPED)
        ->and($new2?->state)->toBe(ExternalIdentity::STATE_REMAPPED)
        ->and($new1?->replaced_by_identity_id)->toBe($old1?->id)
        ->and($new2?->replaced_by_identity_id)->toBe($old2?->id)
        ->and((int) $identities->resolve($f['oldConnectionId'], rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Employee, 'OLD-EMP-1'))->id)
        ->toBe($f['entityIds']['OLD-EMP-1'])
        ->and((int) $identities->resolve($f['oldConnectionId'], rollbackRef(ROLLBACK_OLD_PROVIDER, WorkforceResourceType::Employee, 'OLD-EMP-2'))->id)
        ->toBe($f['entityIds']['OLD-EMP-2'])
        ->and($new1?->workforce_entity_id)->toBe($f['entityIds']['OLD-EMP-1'])
        // Nothing is deleted: the two rows the remap created stay, remapped.
        ->and(count(rollbackRows($f['tenantId'])))->toBe($rowsBefore + 2);

    $reverse = DB::table('people_connector_connector_workforce_snapshots')
        ->where('workforce_entity_id', $f['entityIds']['OLD-EMP-1'])
        ->where('event_type', 'identity_handed_over')
        ->orderByDesc('id')
        ->first();

    expect(json_decode((string) $reverse->payload, true))->toMatchArray([
        'external_id' => 'NEW-EMP-1',
        'replacement_external_id' => 'OLD-EMP-1',
        'replacement_provider_id' => ROLLBACK_OLD_PROVIDER,
        'replacement_connection_id' => (string) $f['oldConnectionId'],
    ])->and(json_decode((string) $reverse->provenance, true))->toMatchArray([
        'source' => 'provider.replacement_rollback',
        'review_reference' => 'rollback-2026-09-06',
    ]);
});

test('a new identity observed since the handover puts the whole rollback past the boundary', function (): void {
    $f = rollbackFixture('Rollback Observed Tenant');
    $auditId = rollbackRemap($f);
    rollbackObserve($f, 'NEW-EMP-2', '2026-09-05T12:00:00+00:00');
    $before = rollbackRows($f['tenantId']);

    expect(fn () => app(ProviderReplacementService::class)->rollback($f['actor'], $auditId, 'rollback-2026-09-06'))
        ->toThrow(ProviderReplacementException::class, 'NEW-EMP-2');

    // The first pair was still inside the boundary. Neither moves.
    expect(rollbackRows($f['tenantId']))->toBe($before)
        ->and(rollbackAudits($f['tenantId']))->toBe(0);
});

test('a retired source connection makes the handover history', function (): void {
    $f = rollbackFixture('Rollback Retired Tenant');
    $auditId = rollbackRemap($f);
    app(ConnectionRetirementService::class)->retire($f['actor'], $f['oldConnectionId'], 'retire-2026-09-05');
    $before = rollbackRows($f['tenantId']);

    expect(fn () => app(ProviderReplacementService::class)->rollback($f['actor'], $auditId, 'rollback-2026-09-06'))
        ->toThrow(ProviderReplacementException::class, 'retired');

    expect(rollbackRows($f['tenantId']))->toBe($before)
        ->and(rollbackAudits($f['tenantId']))->toBe(0);
});

test('rolling the same audit back twice is refused the second time with no write', function (): void {
    $f = rollbackFixture('Rollback Twice Tenant');
    $auditId = rollbackRemap($f);
    $replacements = app(ProviderReplacementService::class);
    $replacements->rollback($f['actor'], $auditId, 'rollback-2026-09-06');
    $before = rollbackRows($f['tenantId']);

    expect(fn () => $replacements->rollback($f['actor'], $auditId, 'rollback-2026-09-07'))
        ->toThrow(ProviderReplacementException::class, 'already been rolled back');

    expect(rollbackRows($f['tenantId']))->toBe($before)
        ->and(rollbackAudits($f['tenantId']))->toBe(1);
});

test('one audit row per rollback names the original audit and the external ids and nothing secret', function (): void {
    $f = rollbackFixture('Rollback Audit Tenant');
    $auditId = rollbackRemap($f);

    app(ProviderReplacementService::class)->rollback($f['actor'], $auditId, 'rollback-2026-09-06');

    $audits = OperatorAudit::query()
        ->forTenant($f['tenantId'])
        ->where('operation', OperatorAuditOperation::IdentitiesRemapRolledBack->value)
        ->get();

    expect($audits)->toHaveCount(1);
    $audit = $audits->first();
    expect($audit->connection_id)->toBe($f['newConnectionId'])
        ->and($audit->related_connection_id)->toBe($f['oldConnectionId'])
        ->and($audit->review_reference)->toBe('rollback-2026-09-06')
        ->and($audit->before_summary)->toMatchArray(['audit_id' => $auditId, 'external_ids' => ['NEW-EMP-1', 'NEW-EMP-2']])
        ->and($audit->after_summary)->toMatchArray(['rolled_back' => 2, 'external_ids' => ['OLD-EMP-1', 'OLD-EMP-2']]);

    foreach (array_keys($audit->before_summary + $audit->after_summary) as $key) {
        expect($key)->not->toMatch('/secret|token|password|credential|payload/i');
    }
});

test('a rollback without the identity management capability is refused before any write', function (): void {
    $f = rollbackFixture('Rollback Capability Tenant');
    $auditId = rollbackRemap($f);
    $before = rollbackRows($f['tenantId']);
    rollbackAuthz(false);

    expect(fn () => app(ProviderReplacementService::class)->rollback($f['actor'], $auditId, 'rollback-2026-09-06'))
        ->toThrow(ProviderAuthorizationException::class);

    expect(rollbackRows($f['tenantId']))->toBe($before)
        ->and(rollbackAudits($f['tenantId']))->toBe(0);
});

test('an audit from another tenant is not found', function (): void {
    $f = rollbackFixture('Rollback Isolation Tenant');
    $other = rollbackFixture('Rollback Other Tenant');
    $otherAuditId = rollbackRemap($other);
    app(TenantContext::class)->set($f['tenantId']);

    // The audit lookup is what refuses, not the connection locator behind it:
    // an audit id from another tenant must never be read this far.
    expect(fn () => app(ProviderReplacementService::class)->rollback($f['actor'], $otherAuditId, 'rollback-2026-09-06'))
        ->toThrow(ConnectorRecordNotFoundException::class, 'replacement audit was not found');

    expect(rollbackIdentity($other['tenantId'], $other['oldConnectionId'], 'OLD-EMP-1')?->state)
        ->toBe(ExternalIdentity::STATE_REMAPPED)
        ->and(rollbackAudits($other['tenantId']))->toBe(0);
});

test('a rollback onto a connection in another company scope is refused', function (): void {
    $f = rollbackFixture('Rollback Cross Scope Tenant');
    $sibling = Company::factory()->create(['tenant_id' => $f['tenantId'], 'name' => 'Sibling Co']);
    $store = app(ProviderConnectionStore::class);
    $siblingConnection = $store->configure(ProviderScope::company((int) $sibling->id), ROLLBACK_OLD_PROVIDER);
    $siblingConnectionId = (int) $store->activate((int) $siblingConnection->id)->id;
    rollbackRemap($f);

    // remap() would never write this row; an audit that names a sibling scope
    // as the source is the only way a rollback could be asked to hand identities
    // across scopes, and the scope rule holds for the reverse direction too.
    $forged = app(OperatorAuditLog::class)->record(
        $f['actor'],
        OperatorAuditOperation::IdentitiesRemapped,
        $siblingConnectionId,
        $f['newConnectionId'],
        'replacement-2026-09-05',
        ['external_ids' => ['OLD-EMP-1']],
        ['external_ids' => ['NEW-EMP-1']],
        new DateTimeImmutable(ROLLBACK_REMAP_AT),
    );
    $before = rollbackRows($f['tenantId']);

    expect(fn () => app(ProviderReplacementService::class)->rollback($f['actor'], (int) $forged->id, 'rollback-2026-09-06'))
        ->toThrow(ProviderReplacementException::class, 'scope');

    expect(rollbackRows($f['tenantId']))->toBe($before)
        ->and(rollbackAudits($f['tenantId']))->toBe(0);
});

test('the command reverses the handover and exits non-zero on a refusal', function (): void {
    $f = rollbackFixture('Rollback Command Tenant');
    $auditId = rollbackRemap($f);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);

    $this->artisan('people-connector:replacement-rollback', [
        'audit' => $auditId, '--tenant' => $f['tenantId'], '--as' => $operator->id, '--review' => 'rollback-2026-09-06',
    ])->expectsOutputToContain('Rolled back 2')->assertExitCode(0);

    $this->artisan('people-connector:replacement-rollback', [
        'audit' => $auditId, '--tenant' => $f['tenantId'], '--as' => $operator->id, '--review' => 'rollback-2026-09-07',
    ])->assertExitCode(1);

    app(TenantContext::class)->set($f['tenantId']);
    expect(rollbackIdentity($f['tenantId'], $f['oldConnectionId'], 'OLD-EMP-1')?->state)->toBe(ExternalIdentity::STATE_ACTIVE)
        ->and(rollbackAudits($f['tenantId']))->toBe(1);
});
