<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Company\Models\Company;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Services\OperatorAuditLog;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
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
});

afterEach(fn () => app(TenantContext::class)->clear());

test('identity audit trail prints mapping merge and export in order for only the operator tenant', function (): void {
    $externalId = 'AUDIT-EMP-1';
    identityAuditFixture('Foreign Audit Tenant', $externalId, withHistory: false);
    $target = identityAuditFixture('Target Audit Tenant', $externalId, withHistory: true);

    expect(Artisan::call('connector:identity:audit-trail', [
        'external-id' => $externalId,
        '--tenant' => $target['tenantId'],
        '--as' => $target['operator']->id,
        '--json' => true,
    ]))->toBe(0);

    $rows = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR)['events'];
    expect(array_column($rows, 'event'))->toBe([
        'identity_attached',
        'entity_merged',
        'subject.history_exported',
    ])->and(array_column($rows, 'actor'))->toBe([
        'system',
        'system',
        'user:'.$target['operator']->id,
    ]);
});

test('identity audit trail exits non-zero for an external id outside the operator tenant', function (): void {
    identityAuditFixture('Foreign Only Audit Tenant', 'FOREIGN-ONLY', withHistory: false);
    $target = identityAuditFixture('Empty Audit Tenant', 'LOCAL-ID', withHistory: false);

    expect(Artisan::call('connector:identity:audit-trail', [
        'external-id' => 'FOREIGN-ONLY',
        '--tenant' => $target['tenantId'],
        '--as' => $target['operator']->id,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('was not found in the current tenant')
        ->not->toContain('Foreign Only Audit Tenant');
});

test('identity audit trail refuses an operator from another company of the same tenant', function (): void {
    $target = identityAuditFixture('Shared Audit Tenant', 'SHARED-ID', withHistory: false);
    $otherCompany = Company::factory()->create(['tenant_id' => $target['tenantId']]);
    $outsider = User::factory()->create(['company_id' => $otherCompany->id]);

    expect(Artisan::call('connector:identity:audit-trail', [
        'external-id' => 'SHARED-ID',
        '--tenant' => $target['tenantId'],
        '--as' => $outsider->id,
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('must belong to the identity tenant and company');
});

/** @return array{tenantId: int, operator: User} */
function identityAuditFixture(string $name, string $externalId, bool $withHistory): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    $operator = User::factory()->create(['company_id' => $company->id]);
    app(TenantContext::class)->set($tenantId);

    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->configure(ProviderScope::company((int) $company->id), 'test.identity-audit');
    $connection = $connections->activate((int) $connection->id);
    $identities = app(WorkforceIdentityStore::class);
    $subject = new ExternalReference('test.identity-audit', WorkforceResourceType::Employee, $externalId);
    $identity = $identities->resolveOrCreateIdentity((int) $connection->id, $subject, new DateTimeImmutable('2026-09-06T08:00:00+00:00'));

    if ($withHistory) {
        $survivor = new ExternalReference('test.identity-audit', WorkforceResourceType::Employee, 'AUDIT-SURVIVOR');
        $identities->resolveOrCreateIdentity((int) $connection->id, $survivor, new DateTimeImmutable('2026-09-06T08:30:00+00:00'));
        $identities->merge(
            (int) $connection->id,
            $subject,
            $survivor,
            new DateTimeImmutable('2026-09-06T09:00:00+00:00'),
            new WorkforceProvenance('reconciliation.review', 'review-233'),
        );
        app(OperatorAuditLog::class)->record(
            Actor::forUser($operator),
            OperatorAuditOperation::SubjectHistoryExported,
            (int) $connection->id,
            null,
            null,
            [],
            ['workforce_entity_id' => (int) $identity->workforce_entity_id, 'rows' => 2],
            new DateTimeImmutable('2026-09-06T10:00:00+00:00'),
        );
    }

    return ['tenantId' => $tenantId, 'operator' => $operator];
}
