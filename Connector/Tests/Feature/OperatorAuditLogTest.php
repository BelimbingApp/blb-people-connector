<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\AppendOnlyRecordException;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectionRetirementException;
use App\Domains\PeopleConnector\Connector\Exceptions\OperatorAuditException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Livewire\Audit\Index as AuditIndex;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\CutoverRehearsalService;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use App\Domains\PeopleConnector\Connector\Services\RetentionPurger;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\View\ViewException;
use Livewire\Livewire;

/**
 * Every operator action on a connection leaves one audit row with actor,
 * tenant, connection and a redacted before/after summary (#199); the writer
 * refuses contents; rows are tenant-scoped and append-only; operators read
 * them on a listing page.
 */
afterEach(function (): void {
    app(TenantContext::class)->clear();
});

const OPERATOR_AUDIT_OLD_PROVIDER = 'test.audit-old';
const OPERATOR_AUDIT_NEW_PROVIDER = 'test.audit-new';

function operatorAuditAuthz(bool $allow): void
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
                throw new ProviderAuthorizationException(providerId: 'connector', operation: 'operator_audit', message: 'The actor lacks the capability.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

function operatorAuditRef(string $provider, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($provider, $type, $id);
}

/**
 * One tenant with an old active connection holding one employee, and a new
 * configured connection alongside it, so every operator action has a target.
 *
 * @return array{tenantId: int, companyId: int, oldId: int, newId: int, actor: Actor, at: DateTimeImmutable}
 */
function operatorAuditFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    operatorAuditAuthz(true);
    $store = app(ProviderConnectionStore::class);
    $scope = ProviderScope::company((int) $company->id);

    $old = $store->configure($scope, OPERATOR_AUDIT_OLD_PROVIDER);
    $oldId = (int) $store->activate((int) $old->id)->id;
    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $companyRef = operatorAuditRef(OPERATOR_AUDIT_OLD_PROVIDER, WorkforceResourceType::Company, 'AUD-CO');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($oldId, new WorkforceCompany($companyRef, 'Audit Co', true, $at));
    $projections->upsert($oldId, new WorkforceEmployee(
        reference: operatorAuditRef(OPERATOR_AUDIT_OLD_PROVIDER, WorkforceResourceType::Employee, 'AUD-EMP-1'),
        companyReference: $companyRef,
        displayName: 'Audit Person',
        active: true,
        effectiveAt: $at,
        observedAt: $at,
    ));
    app(WorkforceIdentityStore::class)->resolve($oldId, operatorAuditRef(OPERATOR_AUDIT_OLD_PROVIDER, WorkforceResourceType::Employee, 'AUD-EMP-1'));

    $new = $store->configure($scope, OPERATOR_AUDIT_NEW_PROVIDER);
    $newId = (int) $store->activate((int) $new->id)->id;

    return [
        'tenantId' => $tenantId,
        'companyId' => (int) $company->id,
        'oldId' => $oldId,
        'newId' => $newId,
        'actor' => new Actor(PrincipalType::USER, 4242, (int) $company->id, tenantId: $tenantId),
        'at' => $at,
    ];
}

function operatorAuditRows(int $tenantId): Collection
{
    return OperatorAudit::query()->forTenant($tenantId)->orderBy('id')->get();
}

test('retiring a connection writes one audit row with actor, connection and the status change', function (): void {
    $f = operatorAuditFixture('Audit Retire Tenant');

    app(ConnectionRetirementService::class)->retire($f['actor'], $f['newId'], 'retire-2026-09-06', $f['at']);

    $rows = operatorAuditRows($f['tenantId']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->operation)->toBe(OperatorAuditOperation::ConnectionRetired)
        ->and($rows[0]->connection_id)->toBe($f['newId'])
        ->and($rows[0]->related_connection_id)->toBeNull()
        ->and($rows[0]->actor_type)->toBe(PrincipalType::USER->value)
        ->and($rows[0]->actor_id)->toBe(4242)
        ->and($rows[0]->actor_company_id)->toBe($f['companyId'])
        ->and($rows[0]->review_reference)->toBe('retire-2026-09-06')
        ->and($rows[0]->before_summary)->toMatchArray(['status' => 'active', 'provider_id' => OPERATOR_AUDIT_NEW_PROVIDER])
        ->and($rows[0]->after_summary)->toMatchArray(['status' => 'retired', 'scheduler_grants_revoked' => true])
        ->and($rows[0]->occurred_at->format(DATE_ATOM))->toBe($f['at']->format(DATE_ATOM));
});

test('a refused retirement writes no audit row', function (): void {
    $f = operatorAuditFixture('Audit Retire Refused Tenant');

    expect(fn () => app(ConnectionRetirementService::class)->retire($f['actor'], $f['newId'], '   '))
        ->toThrow(ConnectionRetirementException::class, 'requires a review reference');

    expect(operatorAuditRows($f['tenantId']))->toHaveCount(0);
});

test('a provider replacement writes one audit row naming both connections and the reviewed ids', function (): void {
    $f = operatorAuditFixture('Audit Remap Tenant');

    app(ProviderReplacementService::class)->remap(
        $f['actor'],
        $f['oldId'],
        $f['newId'],
        [new ProviderIdentityMapping(
            operatorAuditRef(OPERATOR_AUDIT_OLD_PROVIDER, WorkforceResourceType::Employee, 'AUD-EMP-1'),
            operatorAuditRef(OPERATOR_AUDIT_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-1'),
        )],
        'replace-2026-09-06',
        $f['at'],
    );

    $rows = operatorAuditRows($f['tenantId']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->operation)->toBe(OperatorAuditOperation::IdentitiesRemapped)
        ->and($rows[0]->connection_id)->toBe($f['oldId'])
        ->and($rows[0]->related_connection_id)->toBe($f['newId'])
        ->and($rows[0]->review_reference)->toBe('replace-2026-09-06')
        ->and($rows[0]->before_summary)->toMatchArray(['provider_id' => OPERATOR_AUDIT_OLD_PROVIDER, 'external_ids' => ['AUD-EMP-1']])
        ->and($rows[0]->after_summary)->toMatchArray(['provider_id' => OPERATOR_AUDIT_NEW_PROVIDER, 'remapped' => 1, 'external_ids' => ['NEW-EMP-1']]);
});

test('a cutover rehearsal writes one audit row with its findings and nothing else', function (): void {
    $f = operatorAuditFixture('Audit Rehearse Tenant');

    $report = app(CutoverRehearsalService::class)->rehearse($f['actor'], $f['oldId'], $f['newId']);

    $rows = operatorAuditRows($f['tenantId']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->operation)->toBe(OperatorAuditOperation::CutoverRehearsed)
        ->and($rows[0]->connection_id)->toBe($f['oldId'])
        ->and($rows[0]->related_connection_id)->toBe($f['newId'])
        ->and($rows[0]->review_reference)->toBeNull()
        ->and($rows[0]->before_summary)->toMatchArray(['unmapped_identities' => $report->unmappedIdentities, 'open_issues' => $report->openIssues])
        ->and($rows[0]->after_summary)->toMatchArray(['blocked' => $report->blocked(), 'blockers' => $report->blockers()]);
});

test('a retention purge writes one audit row with expected and deleted counts per table', function (): void {
    $f = operatorAuditFixture('Audit Purge Tenant');
    app(ReconciliationIssueStore::class)->report(
        $f['oldId'],
        'sync:employee:OLD',
        'sync_conflict',
        new ReconciliationIssueDetails(reasonCode: 'review_required'),
        WorkforceResourceType::Employee->value,
        'OLD',
        seenAt: new DateTimeImmutable('2020-01-01T00:00:00+00:00'),
    );
    $retention = [];
    foreach (DomainModels::all() as $model) {
        if (is_subclass_of($model, Model::class)) {
            $retention[(new $model)->getTable()] = ['days' => null];
        }
    }
    $retention['people_connector_connector_reconciliation_issues'] = ['days' => 30, 'column' => 'first_seen_at'];
    config()->set('people-connector.retention', $retention);

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-06T00:00:00+00:00'));
    $result = app(RetentionPurger::class)->purge($f['actor'], $report, new DateTimeImmutable('2026-09-06T01:00:00+00:00'));

    $rows = operatorAuditRows($f['tenantId']);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]->operation)->toBe(OperatorAuditOperation::RetentionPurged)
        ->and($rows[0]->connection_id)->toBeNull()
        ->and($rows[0]->review_reference)->toBe($result->runId)
        ->and($rows[0]->before_summary['tables'])->toContain('people_connector_connector_reconciliation_issues')
        ->and($rows[0]->after_summary['total_deleted'])->toBe($result->totalDeleted())
        ->and($result->totalDeleted())->toBeGreaterThan(0);
});

test('the writer refuses a summary that names a credential, token or payload, and writes nothing', function (string $key): void {
    $f = operatorAuditFixture('Audit Redaction Tenant');

    expect(fn () => app(OperatorAuditLog::class)->record(
        $f['actor'], OperatorAuditOperation::ConnectionRetired, $f['oldId'], null, 'ref', [$key => 'x'], [],
    ))->toThrow(OperatorAuditException::class, 'never enter the operator audit');

    expect(operatorAuditRows($f['tenantId']))->toHaveCount(0);
})->with(['api_token', 'client_secret', 'password', 'credential_hint', 'raw_payload', 'Authorization']);

test('the writer refuses contents: nested structures, objects and long strings', function (mixed $value, string $fragment): void {
    $f = operatorAuditFixture('Audit Contents Tenant');

    expect(fn () => app(OperatorAuditLog::class)->record(
        $f['actor'], OperatorAuditOperation::ConnectionRetired, $f['oldId'], null, 'ref', [], ['detail' => $value],
    ))->toThrow(OperatorAuditException::class, $fragment);

    expect(operatorAuditRows($f['tenantId']))->toHaveCount(0);
})->with([
    'a keyed map' => [['inner' => ['deep' => 1]], 'keyed map'],
    'a list holding a list' => [[[1, 2]], 'must be a scalar'],
    'an object' => [new stdClass, 'must be a scalar'],
    'a long string' => [str_repeat('x', 191), 'longer than a summary'],
]);

test('the writer refuses a keyed sub-array: its keys were never checked and would be discarded', function (array $value): void {
    $f = operatorAuditFixture('Audit Nested Key Tenant');

    expect(fn () => app(OperatorAuditLog::class)->record(
        $f['actor'], OperatorAuditOperation::ConnectionRetired, $f['oldId'], null, 'ref', [], ['meta' => $value],
    ))->toThrow(OperatorAuditException::class, 'keyed map');

    expect(operatorAuditRows($f['tenantId']))->toHaveCount(0);
})->with([
    'a credential under a nested key' => [['token' => 'sk_live_51H8xQpAbCdEfGhIjKlMnOpQr']],
    'a harmless-looking nested key' => [['note' => 'x']],
]);

test('a list of reviewed identifiers is still accepted', function (): void {
    $f = operatorAuditFixture('Audit List Tenant');

    $row = app(OperatorAuditLog::class)->record(
        $f['actor'], OperatorAuditOperation::ConnectionRetired, $f['oldId'], null, 'ref', [], ['reviewed_external_ids' => ['AUD-EMP-1', 'AUD-EMP-2']],
    );

    expect($row->after_summary['reviewed_external_ids'])->toBe(['AUD-EMP-1', 'AUD-EMP-2']);
});

test('the writer refuses an actor from another tenant', function (): void {
    $f = operatorAuditFixture('Audit Actor Tenant');
    $elsewhere = new Actor(PrincipalType::USER, 1, $f['companyId'], tenantId: $f['tenantId'] + 1000);

    expect(fn () => app(OperatorAuditLog::class)->record($elsewhere, OperatorAuditOperation::ConnectionRetired, $f['oldId'], null, null, [], []))
        ->toThrow(OperatorAuditException::class, 'inside the current tenant');
});

test('audit rows are append-only and scoped to their tenant', function (): void {
    $a = operatorAuditFixture('Audit Tenant A');
    app(ConnectionRetirementService::class)->retire($a['actor'], $a['newId'], 'retire-a');
    $b = operatorAuditFixture('Audit Tenant B');
    app(ConnectionRetirementService::class)->retire($b['actor'], $b['newId'], 'retire-b');

    expect(operatorAuditRows($a['tenantId'])->pluck('review_reference')->all())->toBe(['retire-a'])
        ->and(operatorAuditRows($b['tenantId'])->pluck('review_reference')->all())->toBe(['retire-b']);

    $row = operatorAuditRows($b['tenantId'])->first();
    expect(fn () => $row->update(['review_reference' => 'edited']))->toThrow(AppendOnlyRecordException::class)
        ->and(fn () => $row->delete())->toThrow(AppendOnlyRecordException::class);
});

test('an operator reads a connection audit listing, and a connection of another tenant is not found', function (): void {
    $a = operatorAuditFixture('Audit Listing Tenant');
    app(ConnectionRetirementService::class)->retire($a['actor'], $a['newId'], 'retire-listed');
    app(CutoverRehearsalService::class)->rehearse($a['actor'], $a['oldId'], $a['newId']);
    $user = User::factory()->create(['company_id' => $a['companyId']]);

    Livewire::actingAs($user)
        ->test(AuditIndex::class, ['connectionId' => $a['newId']])
        ->assertOk()
        ->assertSee('Connection retired')
        ->assertSee('Cutover rehearsed')
        ->assertSee('retire-listed');

    $b = operatorAuditFixture('Audit Listing Other Tenant');
    $other = User::factory()->create(['company_id' => $b['companyId']]);

    // Livewire wraps a mount exception in ViewException; the message is the
    // tenant-scoped locator's, so the other tenant's connection is not found.
    expect(fn () => Livewire::actingAs($other)->test(AuditIndex::class, ['connectionId' => $a['newId']]))
        ->toThrow(ViewException::class, 'not found in the current tenant');
});

test('the audit stream filter includes only the selected connection and stream', function (): void {
    $a = operatorAuditFixture('Stream Audit Tenant');
    $writer = app(OperatorAuditLog::class);
    $matching = $writer->record($a['actor'], OperatorAuditOperation::SyncPass, $a['oldId'], null, null, [], ['stream' => 'workforce']);
    $otherStream = $writer->record($a['actor'], OperatorAuditOperation::SyncPass, $a['oldId'], null, null, [], ['stream' => 'other']);
    $writer->record($a['actor'], OperatorAuditOperation::SyncPass, $a['newId'], null, null, [], ['stream' => 'workforce']);
    $user = User::factory()->create(['company_id' => $a['companyId']]);

    Livewire::actingAs($user)->test(AuditIndex::class, ['connectionId' => $a['oldId']])
        ->assertViewHas('rows', fn ($rows) => $rows->modelKeys() === [$otherStream->id, $matching->id])
        ->set('stream', 'workforce')
        ->assertViewHas('rows', fn ($rows) => $rows->modelKeys() === [$matching->id])
        ->set('stream', 'missing')
        ->assertViewHas('rows', fn ($rows) => $rows->isEmpty())
        ->set('stream', '')
        ->assertViewHas('rows', fn ($rows) => $rows->modelKeys() === [$otherStream->id, $matching->id]);
});

test('the audit listing is refused without the connection capability', function (): void {
    $a = operatorAuditFixture('Audit Listing Denied Tenant');
    $user = User::factory()->create(['company_id' => $a['companyId']]);
    operatorAuditAuthz(false);

    expect(fn () => Livewire::actingAs($user)->test(AuditIndex::class, ['connectionId' => $a['oldId']]))
        ->toThrow(ViewException::class, 'lacks the capability');
});
