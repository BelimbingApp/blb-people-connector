<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Core\User\Models\User;
use App\Domains\People\Skills\Data\AssessmentDraft;
use App\Domains\People\Skills\Data\RequirementItemDraft;
use App\Domains\People\Skills\Data\RequirementProfileDraft;
use App\Domains\People\Skills\Data\RequirementSelectorDraft;
use App\Domains\People\Skills\Data\SkillDraft;
use App\Domains\People\Skills\Enums\AssessmentCycle;
use App\Domains\People\Skills\Enums\AssessmentMethod;
use App\Domains\People\Skills\Enums\RequirementCriticality;
use App\Domains\People\Skills\Enums\SelectorType;
use App\Domains\People\Skills\Services\AssessmentStore;
use App\Domains\People\Skills\Services\RequirementProfileStore;
use App\Domains\People\Skills\Services\SkillCatalogDefaults;
use App\Domains\People\Skills\Services\SkillCatalogStore;
use App\Domains\People\Training\Data\TrainingCourseDraft;
use App\Domains\People\Training\Data\TrainingEventDraft;
use App\Domains\People\Training\Enums\DeliveryMode;
use App\Domains\People\Training\Services\TrainingCatalogStore;
use App\Domains\People\Training\Services\TrainingEventStore;
use App\Domains\People\Training\Services\TrainingParticipationStore;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Data\RetentionReport;
use App\Domains\PeopleConnector\Connector\Data\RetentionTableReport;
use App\Domains\PeopleConnector\Connector\Data\SupplementalTableReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceCompany;
use App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\RetentionPolicyException;
use App\Domains\PeopleConnector\Connector\Models\DomainModels;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Services\ConnectionRetirementService;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderReplacementService;
use App\Domains\PeopleConnector\Connector\Services\RetentionPolicy;
use App\Domains\PeopleConnector\Connector\Services\RetentionPurger;
use App\Domains\PeopleConnector\Connector\Services\SupplementalTableRegister;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Self-contained: every helper is prefixed supplemental and lives here, so the
 * file passes or fails alone for its own reasons. The only outside helper is
 * the platform's createTenantWithCompany(). It runs in the composed suite: the
 * People Skills and Training modules are mounted, and the register is measured
 * against the tables their migrations actually created.
 */

const SUPPLEMENTAL_OLD_PROVIDER = 'test.supplemental.old';

const SUPPLEMENTAL_NEW_PROVIDER = 'test.supplemental.new';

const SUPPLEMENTAL_ASSESSMENTS = 'people_connector_skill_assessments';

const SUPPLEMENTAL_PARTICIPANTS = 'people_training_participants';

const SUPPLEMENTAL_PURGE_AUDITS = 'people_connector_connector_retention_purge_audits';

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function supplementalAuthz(bool $allow): void
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
                    operation: 'review_retention',
                    message: 'The actor lacks the capability ['.$capability.'].',
                );
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return $this->allow ? collect($resources) : collect();
        }
    });
}

/** Every connector-owned table declared indefinite: a complete, purge-free policy. */
function supplementalIndefinitePolicy(): void
{
    $owned = array_values(array_unique(array_map(
        static fn (string $model): string => (new $model)->getTable(),
        array_filter(DomainModels::all(), static fn (string $model): bool => is_subclass_of($model, Model::class)),
    )));
    config()->set('people-connector.retention', array_fill_keys($owned, ['days' => null]));
}

function supplementalRef(string $providerId, WorkforceResourceType $type, string $id): ExternalReference
{
    return new ExternalReference($providerId, $type, $id);
}

/** @return array<string, int> row count per register table for one tenant, keyed by table */
function supplementalCounts(int $tenantId): array
{
    $counts = [];
    foreach (app(SupplementalTableRegister::class)->tables() as $table) {
        $counts[$table] = DB::table($table)->where('tenant_id', $tenantId)->count();
    }

    return $counts;
}

/**
 * One tenant with a company-scoped active connection projecting one employee,
 * and the supplemental records the connector must never touch: a skill
 * assessment and a training participant for employees of that company.
 *
 * @return array{tenantId: int, companyId: int, connectionId: int, actor: Actor}
 */
function supplementalFixture(string $name): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);
    $tenantId = (int) $tenant->id;
    $companyId = (int) $company->id;
    app(TenantContext::class)->set($tenantId);

    $store = app(ProviderConnectionStore::class);
    $connection = $store->configure(ProviderScope::company($companyId), SUPPLEMENTAL_OLD_PROVIDER);
    $connectionId = (int) $store->activate((int) $connection->id)->id;

    $at = new DateTimeImmutable('2026-09-01T08:00:00+00:00');
    $companyRef = supplementalRef(SUPPLEMENTAL_OLD_PROVIDER, WorkforceResourceType::Company, 'SUP-CO');
    $projections = app(WorkforceProjectionStore::class);
    $projections->upsert($connectionId, new WorkforceCompany($companyRef, 'Supplemental Co', true, $at));
    $projections->upsert($connectionId, new WorkforceEmployee(
        reference: supplementalRef(SUPPLEMENTAL_OLD_PROVIDER, WorkforceResourceType::Employee, 'SUP-EMP-1'),
        companyReference: $companyRef,
        displayName: 'Ada Supplemental',
        active: true,
        effectiveAt: $at,
        observedAt: $at,
    ));
    app(WorkforceIdentityStore::class)->resolve(
        $connectionId,
        supplementalRef(SUPPLEMENTAL_OLD_PROVIDER, WorkforceResourceType::Employee, 'SUP-EMP-1'),
    );

    $employee = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Ada Supplemental', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);
    $organizer = Employee::factory()->create(['company_id' => $companyId, 'full_name' => 'Bo Organiser', 'short_name' => null, 'supervisor_id' => null, 'status' => 'active', 'employee_type' => 'full_time']);

    supplementalAuthz(true);

    // Seeded through the owning modules' public stores, never by writing to
    // People tables from here: the connector declares no dependency on the
    // Skills and Training schemas, only on the register that names them.
    $catalog = app(SkillCatalogStore::class);
    $category = $catalog->defineCategory($companyId, 'safety', 'Safety');
    $skill = $catalog->defineSkill($companyId, new SkillDraft('isolation.energy', 'Energy isolation', 'Isolate.', (int) $category->id));
    app(SkillCatalogDefaults::class)->install($companyId);

    $profiles = app(RequirementProfileStore::class);
    $profile = $profiles->draft($companyId, new RequirementProfileDraft(
        code: 'supplemental.isolation',
        name: 'Supplemental Isolation',
        selectors: [new RequirementSelectorDraft(SelectorType::Company)],
        items: [new RequirementItemDraft(
            skillId: (int) $skill->id,
            sequence: 1,
            requiredLevel: 3,
            criticality: RequirementCriticality::Critical,
            weightPercent: 100.0,
        )],
    ));
    $profiles->publish($companyId, (int) $profile->id);

    app(AssessmentStore::class)->draft($companyId, new AssessmentDraft(
        employeeEntityId: (int) $employee->id,
        skillId: (int) $skill->id,
        assessedLevel: 3,
        method: AssessmentMethod::DirectObservation,
        cycle: AssessmentCycle::Annual,
        assessedAt: now(),
        evidence: 'Observed one supervised isolation drill.',
    ));

    $course = app(TrainingCatalogStore::class)->defineCourse($companyId, new TrainingCourseDraft(
        code: 'isolation.induction', title: 'Isolation induction', deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id], internalTrainerEmployeeEntityId: (int) $organizer->id,
    ));
    $event = app(TrainingEventStore::class)->schedule($companyId, new TrainingEventDraft(
        courseId: (int) $course->id, startsAt: now()->addDays(5), endsAt: now()->addDays(6), capacity: 10,
        organizerEmployeeEntityId: (int) $organizer->id,
    ));
    app(TrainingParticipationStore::class)->enrolFromRequest(
        User::factory()->create(['company_id' => $companyId]),
        $companyId,
        (int) $event->id,
        [['provider_id' => SUPPLEMENTAL_OLD_PROVIDER, 'employee_subject_id' => 'SUP-EMP-1']],
    );

    return [
        'tenantId' => $tenantId,
        'companyId' => $companyId,
        'connectionId' => $connectionId,
        'actor' => new Actor(PrincipalType::USER, 8001, $companyId, tenantId: $tenantId),
    ];
}

test('the register lists every table the mounted Skills and Training migrations created', function (): void {
    $expected = array_values(array_filter(
        Schema::getTableListing(null, false),
        static fn (string $table): bool => str_starts_with($table, 'people_connector_skill_')
            || str_starts_with($table, 'people_connector_training_')
            || str_starts_with($table, 'people_training_'),
    ));
    sort($expected);

    $register = app(SupplementalTableRegister::class)->tables();

    // Compared against the schema, not a hand-written list: a People migration
    // that adds a table tomorrow must land in the register the moment it runs.
    expect($register)->toBe($expected)
        ->and(array_diff($expected, $register))->toBe([])
        ->and($register)->toContain(SUPPLEMENTAL_ASSESSMENTS, SUPPLEMENTAL_PARTICIPANTS)
        ->and(count($register))->toBeGreaterThanOrEqual(40);
});

test('retiring a connection that seeded assessments and participants leaves every register table unchanged', function (): void {
    $f = supplementalFixture('Supplemental Retirement Tenant');
    $before = supplementalCounts($f['tenantId']);
    expect($before[SUPPLEMENTAL_ASSESSMENTS])->toBe(1)->and($before[SUPPLEMENTAL_PARTICIPANTS])->toBe(1);

    app(ConnectionRetirementService::class)->retire($f['actor'], $f['connectionId'], 'retirement-2026-09-08');

    expect(supplementalCounts($f['tenantId']))->toBe($before);
});

test('replacing the provider and rolling it back leaves every register table unchanged at both steps', function (): void {
    $f = supplementalFixture('Supplemental Replacement Tenant');
    $store = app(ProviderConnectionStore::class);
    $new = $store->configure(ProviderScope::company($f['companyId']), SUPPLEMENTAL_NEW_PROVIDER);
    $newConnectionId = (int) $store->activate((int) $new->id)->id;
    $before = supplementalCounts($f['tenantId']);
    expect($before[SUPPLEMENTAL_ASSESSMENTS])->toBe(1)->and($before[SUPPLEMENTAL_PARTICIPANTS])->toBe(1);

    $replacement = app(ProviderReplacementService::class);
    $replacement->remap(
        $f['actor'],
        $f['connectionId'],
        $newConnectionId,
        [new ProviderIdentityMapping(
            supplementalRef(SUPPLEMENTAL_OLD_PROVIDER, WorkforceResourceType::Employee, 'SUP-EMP-1'),
            supplementalRef(SUPPLEMENTAL_NEW_PROVIDER, WorkforceResourceType::Employee, 'NEW-EMP-1'),
        )],
        'replacement-2026-09-08',
    );
    expect(supplementalCounts($f['tenantId']))->toBe($before);

    $auditId = (int) OperatorAudit::query()
        ->forTenant($f['tenantId'])
        ->where('operation', OperatorAuditOperation::IdentitiesRemapped->value)
        ->orderByDesc('id')
        ->firstOrFail()
        ->id;
    $rolledBack = $replacement->rollback($f['actor'], $auditId, 'rollback-2026-09-08');

    expect($rolledBack->remapped)->toBe(1)
        ->and(supplementalCounts($f['tenantId']))->toBe($before);
});

test('a purge handed a one-day rule for a register table is refused by name and writes nothing', function (): void {
    $f = supplementalFixture('Supplemental Purge Tenant');
    supplementalIndefinitePolicy();
    config()->set('people-connector.retention.'.SUPPLEMENTAL_ASSESSMENTS, ['days' => 1, 'column' => 'created_at']);
    $reviewedAt = new DateTimeImmutable('2026-09-08T12:00:00+00:00');
    $report = new RetentionReport($f['tenantId'], $reviewedAt, [
        SUPPLEMENTAL_ASSESSMENTS => new RetentionTableReport(SUPPLEMENTAL_ASSESSMENTS, 1, 'created_at', 1),
    ]);
    $before = supplementalCounts($f['tenantId']);
    $auditsBefore = DB::table(SUPPLEMENTAL_PURGE_AUDITS)->count();

    expect(fn () => app(RetentionPurger::class)->purge($f['actor'], $report, $reviewedAt))
        ->toThrow(RetentionPolicyException::class, 'never purged, retention indefinite. Nothing was deleted.')
        ->and(supplementalCounts($f['tenantId']))->toBe($before)
        ->and(DB::table(SUPPLEMENTAL_PURGE_AUDITS)->count())->toBe($auditsBefore);

    // The review refuses the same rule for the same reason, so the report the
    // purge command would build never carries it either.
    expect(fn () => app(RetentionPolicy::class)->review($f['actor'], $reviewedAt))
        ->toThrow(RetentionPolicyException::class, 'cannot carry a retention rule');
});

test('the retention report shows every register table as supplemental and indefinite, per tenant', function (): void {
    $f = supplementalFixture('Supplemental Report Tenant');
    supplementalIndefinitePolicy();
    $tables = app(SupplementalTableRegister::class)->tables();

    $report = app(RetentionPolicy::class)->review($f['actor'], new DateTimeImmutable('2026-09-08T12:00:00+00:00'));

    expect(array_keys($report->supplemental))->toBe($tables)
        ->and(array_keys($report->tables))->not->toContain(SUPPLEMENTAL_ASSESSMENTS)
        ->and($report->supplemental[SUPPLEMENTAL_ASSESSMENTS])->toBeInstanceOf(SupplementalTableReport::class)
        ->and($report->supplemental[SUPPLEMENTAL_ASSESSMENTS]->rows)->toBe(1)
        ->and($report->supplemental[SUPPLEMENTAL_PARTICIPANTS]->rows)->toBe(1);
    foreach ($report->supplemental as $entry) {
        expect($entry->isIndefinite())->toBeTrue()
            ->and($entry->days)->toBeNull()
            ->and($entry->providerIndependent)->toBeTrue();
    }

    // A sibling tenant's operator sees the same table names and none of this
    // tenant's rows.
    [$sibling, $siblingCompany] = createTenantWithCompany(['name' => 'Supplemental Sibling Tenant']);
    app(TenantContext::class)->set((int) $sibling->id);
    $siblingActor = new Actor(PrincipalType::USER, 8002, (int) $siblingCompany->id, tenantId: (int) $sibling->id);

    $siblingReport = app(RetentionPolicy::class)->review($siblingActor, new DateTimeImmutable('2026-09-08T12:00:00+00:00'));

    expect(array_keys($siblingReport->supplemental))->toBe($tables)
        ->and(array_sum(array_map(static fn (SupplementalTableReport $t): int => $t->rows, $siblingReport->supplemental)))->toBe(0);

    app(TenantContext::class)->set($f['tenantId']);
    $operator = User::factory()->create(['company_id' => $f['companyId']]);
    $this->artisan('people-connector:retention-report', ['--tenant' => $f['tenantId'], '--as' => $operator->id])
        ->expectsOutputToContain('Supplemental tables (Skills and Training): never purged, not bound to a connection.')
        ->expectsOutputToContain(SUPPLEMENTAL_PARTICIPANTS)
        ->assertExitCode(0);
});

test('an unauthorized review is refused before the register is read', function (): void {
    $f = supplementalFixture('Supplemental Denied Tenant');
    supplementalIndefinitePolicy();
    supplementalAuthz(false);
    $sentinel = new class extends SupplementalTableRegister
    {
        public int $reads = 0;

        public function tables(): array
        {
            $this->reads++;

            return parent::tables();
        }
    };
    app()->instance(SupplementalTableRegister::class, $sentinel);

    expect(fn () => app(RetentionPolicy::class)->review($f['actor']))
        ->toThrow(ProviderAuthorizationException::class, RetentionPolicy::REVIEW_CAPABILITY)
        ->and($sentinel->reads)->toBe(0);
});
