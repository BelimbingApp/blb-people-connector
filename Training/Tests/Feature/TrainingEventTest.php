<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalRole;
use App\Base\Authz\Models\Role;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Models\SkillActorBinding;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use App\Domains\PeopleConnector\Training\Contracts\SummarizesTrainingParticipation;
use App\Domains\PeopleConnector\Training\Data\TrainingCourseDraft;
use App\Domains\PeopleConnector\Training\Data\TrainingEventDraft;
use App\Domains\PeopleConnector\Training\Enums\DeliveryMode;
use App\Domains\PeopleConnector\Training\Enums\TrainingEventStatus;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingCatalogException;
use App\Domains\PeopleConnector\Training\Exceptions\InvalidTrainingEventException;
use App\Domains\PeopleConnector\Training\Exceptions\TrainingEventNotFoundException;
use App\Domains\PeopleConnector\Training\Livewire\Catalog\Index as CatalogIndex;
use App\Domains\PeopleConnector\Training\Livewire\Event\Index;
use App\Domains\PeopleConnector\Training\Models\TrainingCourse;
use App\Domains\PeopleConnector\Training\Models\TrainingEventAuditEvent;
use App\Domains\PeopleConnector\Training\Services\TrainingAudience;
use App\Domains\PeopleConnector\Training\Services\TrainingCatalogStore;
use App\Domains\PeopleConnector\Training\Services\TrainingEventStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

afterEach(function (): void {
    $this->travelBack();
    app(TenantContext::class)->clear();
});

function trainingEventEntity(int $tenantId, string $type): WorkforceEntity
{
    return WorkforceEntity::query()->create([
        'tenant_id' => $tenantId,
        'resource_type' => $type,
        'state' => WorkforceEntity::STATE_ACTIVE,
        'first_seen_at' => now(),
    ]);
}

function trainingEventIdentity(int $tenantId, ProviderConnection $connection, WorkforceEntity $entity, string $type): ExternalIdentity
{
    $externalId = $type.'-'.$entity->id;

    return ExternalIdentity::query()->create([
        'tenant_id' => $tenantId,
        'connection_id' => $connection->id,
        'workforce_entity_id' => $entity->id,
        'provider_id' => 'test.people',
        'resource_type' => $type,
        'external_id' => $externalId,
        'external_id_hash' => hash('sha256', $externalId),
        'state' => ExternalIdentity::STATE_ACTIVE,
        'effective_from' => now(),
        'last_observed_at' => now(),
    ]);
}

function trainingEventEmployee(
    int $tenantId,
    int $companyEntityId,
    ProviderConnection $connection,
    string $name,
    ?int $departmentId = null,
    ?int $departmentHeadId = null,
): WorkforceEmployeeProjection {
    $employee = trainingEventEntity($tenantId, 'employee');
    $user = trainingEventEntity($tenantId, 'user');
    $identity = trainingEventIdentity($tenantId, $connection, $employee, 'employee');

    return WorkforceEmployeeProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $employee->id,
        'source_identity_id' => $identity->id,
        'company_entity_id' => $companyEntityId,
        'user_entity_id' => $user->id,
        'organization_entity_id' => $departmentId,
        'department_head_entity_id' => $departmentHeadId,
        'display_name' => $name,
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function trainingEventFixture(): array
{
    [$tenant, $platformCompany] = createTenantWithCompany(
        ['name' => 'Training Event Tenant'],
        ['name' => 'Training Event Platform Company'],
    );
    $tenantId = (int) $tenant->id;
    app(TenantContext::class)->set($tenantId);
    $company = trainingEventEntity($tenantId, 'company');
    $connection = ProviderConnection::query()->create([
        'tenant_id' => $tenantId,
        'company_id' => $platformCompany->id,
        'scope_key' => 'company:'.$platformCompany->id,
        'active_scope_key' => 'company:'.$platformCompany->id,
        'provider_id' => 'test.people',
        'status' => ProviderConnection::STATUS_ACTIVE,
    ]);
    $companyIdentity = trainingEventIdentity($tenantId, $connection, $company, 'company');
    WorkforceCompanyProjection::query()->create([
        'tenant_id' => $tenantId,
        'workforce_entity_id' => $company->id,
        'source_identity_id' => $companyIdentity->id,
        'name' => 'Training Workforce Company',
        'active' => true,
        'effective_at' => now(),
        'observed_at' => now(),
    ]);

    $departments = [];
    foreach (['Operations', 'Finance'] as $name) {
        $entity = trainingEventEntity($tenantId, 'organization_unit');
        $identity = trainingEventIdentity($tenantId, $connection, $entity, 'organization_unit');
        WorkforceOrganizationUnitProjection::query()->create([
            'tenant_id' => $tenantId,
            'workforce_entity_id' => $entity->id,
            'source_identity_id' => $identity->id,
            'company_entity_id' => $company->id,
            'name' => $name,
            'active' => true,
            'effective_at' => now(),
            'observed_at' => now(),
        ]);
        $departments[] = (int) $entity->id;
    }

    $head = trainingEventEmployee($tenantId, (int) $company->id, $connection, 'Operations Head', $departments[0]);
    $operations = trainingEventEmployee($tenantId, (int) $company->id, $connection, 'Operations Worker', $departments[0], (int) $head->workforce_entity_id);
    $finance = trainingEventEmployee($tenantId, (int) $company->id, $connection, 'Finance Worker', $departments[1]);
    $trainer = trainingEventEmployee($tenantId, (int) $company->id, $connection, 'Trainer', $departments[0]);

    $category = app(SkillCatalogStore::class)->defineCategory((int) $company->id, 'safety', 'Safety');
    $skill = app(SkillCatalogStore::class)->defineSkill((int) $company->id, new SkillDraft(
        code: 'forklift.operation',
        name: 'Forklift operation',
        definition: 'Operate a forklift safely.',
        categoryId: (int) $category->id,
        defaultAssessmentMethod: AssessmentMethod::DirectObservation,
    ));
    $course = app(TrainingCatalogStore::class)->defineCourse((int) $company->id, new TrainingCourseDraft(
        code: 'forklift.induction',
        title: 'Forklift induction',
        deliveryMode: DeliveryMode::InternalClassroom,
        skillIds: [(int) $skill->id],
        internalTrainerEmployeeEntityId: (int) $trainer->workforce_entity_id,
    ));

    return compact('tenant', 'platformCompany', 'tenantId', 'company', 'connection', 'departments',
        'head', 'operations', 'finance', 'trainer', 'course');
}

function trainingEventDraft(array $fixture, array $overrides = []): TrainingEventDraft
{
    return new TrainingEventDraft(...array_merge([
        'courseId' => (int) $fixture['course']->id,
        'startsAt' => new DateTimeImmutable('2026-10-01T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-10-01T17:00:00+00:00'),
        'capacity' => 20,
        'organizerEmployeeEntityId' => (int) $fixture['operations']->workforce_entity_id,
        'targetDepartmentEntityId' => $fixture['departments'][0],
    ], $overrides));
}

function trainingEventRole(User $user, string $code): void
{
    setupAuthzRoles();
    $role = Role::query()->whereNull('company_id')->where('code', $code)->sole();
    PrincipalRole::query()->create([
        'company_id' => $user->company_id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'role_id' => $role->id,
    ]);
}

test('training events preserve schedule snapshots and terminal audit history', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture), actorUserId: 41,
        actorEmployeeEntityId: (int) $fixture['operations']->workforce_entity_id);

    expect($event->course_code_snapshot)->toBe('forklift.induction')
        ->and($event->course_title_snapshot)->toBe('Forklift induction')
        ->and($event->delivery_mode_snapshot)->toBe(DeliveryMode::InternalClassroom)
        ->and($event->status)->toBe(TrainingEventStatus::Scheduled)
        ->and($event->internal_trainer_employee_entity_id)->toBe((int) $fixture['trainer']->workforce_entity_id);

    $revised = $store->revise((int) $fixture['company']->id, (int) $event->id,
        trainingEventDraft($fixture, ['capacity' => 25, 'venue' => 'Training room']), 41);
    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    $store->start((int) $fixture['company']->id, (int) $event->id, 41);
    $this->travelTo(new DateTimeImmutable('2026-10-01T17:00:00+00:00'));
    $completed = $store->complete((int) $fixture['company']->id, (int) $event->id, 'Signed facilitator report', 41);

    expect($revised->capacity)->toBe(25)
        ->and($completed->status)->toBe(TrainingEventStatus::Completed)
        ->and($completed->completion_evidence)->toBe('Signed facilitator report')
        ->and($store->registerQuery((int) $fixture['company']->id)->pluck('id')->all())->toBe([(int) $event->id])
        ->and(TrainingEventAuditEvent::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)->count())->toBe(4)
        ->and(app(SummarizesTrainingParticipation::class)->forEvents((int) $fixture['company']->id, [(int) $event->id]))->toBe([]);

    $audit = TrainingEventAuditEvent::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)->firstOrFail();
    expect(fn () => $audit->update(['comment' => 'rewrite']))
        ->toThrow(InvalidTrainingEventException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('people_connector_training_event_audit_events')->where('id', $audit->id)->update(['comment' => 'rewrite'])))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('people_connector_training_event_audit_events')->where('id', $audit->id)->delete()))
        ->toThrow(QueryException::class);
});

test('HR can maintain the company-scoped course catalog without exposing a course to another company', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(CatalogIndex::class)
        ->call('startCourse')
        ->set('courseForm.code', 'confined.space')
        ->set('courseForm.title', 'Confined space entry')
        ->set('courseForm.delivery_mode', DeliveryMode::InternalOjt->value)
        ->set('courseForm.skill_ids', [(int) $fixture['course']->skillIds()[0]])
        ->set('courseForm.internal_trainer_employee_entity_id', (int) $fixture['trainer']->workforce_entity_id)
        ->call('saveCourse')
        ->assertHasNoErrors()
        ->assertSee('Confined space entry');

    $saved = TrainingCourse::query()->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
        ->where('code', 'confined.space')->sole();
    expect($saved->mappedSkills()->pluck('id')->all())->toBe([(int) $fixture['course']->skillIds()[0]]);

    $sibling = trainingEventEntity($fixture['tenantId'], 'company');
    expect(TrainingCourse::query()->forCompany($fixture['tenantId'], (int) $sibling->id)->where('code', 'confined.space')->exists())
        ->toBeFalse();
});

test('catalog rejects a sibling-company trainer at the store boundary', function (): void {
    $fixture = trainingEventFixture();
    $sibling = trainingEventEntity($fixture['tenantId'], 'company');
    $siblingTrainer = trainingEventEmployee($fixture['tenantId'], (int) $sibling->id, $fixture['connection'], 'Sibling trainer');

    expect(fn () => app(TrainingCatalogStore::class)->defineCourse((int) $fixture['company']->id, new TrainingCourseDraft(
        code: 'cross-company-trainer', title: 'Cross company trainer', deliveryMode: DeliveryMode::Coaching,
        skillIds: [(int) $fixture['course']->skillIds()[0]], internalTrainerEmployeeEntityId: (int) $siblingTrainer->workforce_entity_id,
    )))->toThrow(InvalidTrainingCatalogException::class, 'Choose an active internal trainer from this company.');
});

test('a HOD cannot reveal catalog management state or invoke catalog mutations', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');
    SkillActorBinding::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['company']->id,
        'platform_user_id' => $hod->id,
        'employee_entity_id' => $fixture['head']->workforce_entity_id,
        'user_entity_id' => $fixture['head']->user_entity_id,
        'confirmed_by_user_id' => $hr->id,
        'review_reference' => 'review:training-catalog-hod',
        'confirmed_at' => now(),
    ]);

    Livewire::actingAs($hod)->test(CatalogIndex::class)
        ->set('courseForm', ['code' => 'forced.course'])
        ->assertDontSee('New course')
        ->assertDontSee('Define course')
        ->assertDontSee('Operations Worker')
        ->assertSee('Forklift induction');

    expect(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('startCourse'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('saveCourse'))
        ->toThrow(AuthorizationDeniedException::class)
        ->and(fn () => Livewire::actingAs($hod)->test(CatalogIndex::class)->call('toggleCourseActive', (int) $fixture['course']->id))
        ->toThrow(AuthorizationDeniedException::class);
});

test('skill and training catalog routes resolve their distinct Livewire components', function (): void {
    $fixture = trainingEventFixture();
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    $this->withoutVite();

    $this->actingAs($hr)->get(route('people-connector.skill.catalog.index'))->assertOk();
    $this->actingAs($hr)->get(route('people-connector.training.catalog.index'))->assertOk();
});

test('event schedule and transitions obey the event clock at the store boundary', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);

    expect(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'startsAt' => new DateTimeImmutable('2026-09-29T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-09-29T17:00:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'must end in the future');

    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $neverStarted = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    expect(fn () => $store->revise((int) $fixture['company']->id, (int) $event->id, trainingEventDraft($fixture, [
        'startsAt' => new DateTimeImmutable('2026-09-29T09:00:00+00:00'),
        'endsAt' => new DateTimeImmutable('2026-09-29T17:00:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'must end in the future');

    $this->travelTo(new DateTimeImmutable('2026-10-01T08:59:59+00:00'));
    expect(fn () => $store->start((int) $fixture['company']->id, (int) $event->id))
        ->toThrow(InvalidTrainingEventException::class, 'before its scheduled start');

    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    expect($store->start((int) $fixture['company']->id, (int) $event->id)->status)
        ->toBe(TrainingEventStatus::InProgress);

    $this->travelTo(new DateTimeImmutable('2026-10-01T16:59:59+00:00'));
    expect(fn () => $store->complete((int) $fixture['company']->id, (int) $event->id, 'Too early'))
        ->toThrow(InvalidTrainingEventException::class, 'before its scheduled end');

    $this->travelTo(new DateTimeImmutable('2026-10-01T17:00:00+00:00'));
    expect($store->complete((int) $fixture['company']->id, (int) $event->id, 'Signed report')->status)
        ->toBe(TrainingEventStatus::Completed)
        ->and(fn () => $store->start((int) $fixture['company']->id, (int) $neverStarted->id))
        ->toThrow(InvalidTrainingEventException::class, 'after its scheduled end');
});

test('livewire reports future transition attempts without falsifying event history', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-09-30T12:00:00+00:00'));
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');

    Livewire::actingAs($hr)->test(Index::class)
        ->set('courseId', (int) $fixture['course']->id)
        ->set('organizerEmployeeEntityId', (int) $fixture['operations']->workforce_entity_id)
        ->set('startsAt', '2026-09-29T09:00')
        ->set('endsAt', '2026-09-29T17:00')
        ->call('save')
        ->assertHasErrors('event')
        ->assertSee('The event must end in the future.');

    Livewire::actingAs($hr)->test(Index::class)
        ->call('start', (int) $event->id)
        ->assertHasErrors('event')
        ->assertSee('The event cannot start before its scheduled start.');

    $this->travelTo(new DateTimeImmutable('2026-10-01T09:00:00+00:00'));
    $store->start((int) $fixture['company']->id, (int) $event->id);
    Livewire::actingAs($hr)->test(Index::class)
        ->set("evidence.{$event->id}", 'Premature report')
        ->call('complete', (int) $event->id)
        ->assertHasErrors('event')
        ->assertSee('The event cannot be completed before its scheduled end.');

    expect($event->refresh()->status)->toBe(TrainingEventStatus::InProgress)
        ->and(TrainingEventAuditEvent::query()
            ->forCompany($fixture['tenantId'], (int) $fixture['company']->id)
            ->where('training_event_id', $event->id)->pluck('event_type')->all())
        ->toBe(['scheduled', 'started']);
});

test('event invariants and sibling company or tenant access fail closed', function (): void {
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);

    expect(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'endsAt' => new DateTimeImmutable('2026-10-01T08:59:00+00:00'),
    ])))->toThrow(InvalidTrainingEventException::class, 'end must be after')
        ->and(fn () => $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, ['capacity' => 0])))
        ->toThrow(InvalidTrainingEventException::class, 'Capacity');

    $siblingCompany = trainingEventEntity($fixture['tenantId'], 'company');
    expect(fn () => $store->schedule((int) $siblingCompany->id, trainingEventDraft($fixture)))
        ->toThrow(InvalidTrainingEventException::class, 'active training course');

    expect(fn () => DB::transaction(fn () => DB::table('people_connector_training_events')->insert([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $siblingCompany->id,
        'event_key' => (string) Str::uuid(),
        'course_id' => $fixture['course']->id,
        'course_code_snapshot' => $fixture['course']->code,
        'course_title_snapshot' => $fixture['course']->title,
        'delivery_mode_snapshot' => DeliveryMode::InternalClassroom->value,
        'organizer_employee_entity_id' => $fixture['operations']->workforce_entity_id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHour(),
        'capacity' => 1,
        'status' => TrainingEventStatus::Scheduled->value,
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    $event = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    expect(fn () => $store->start((int) $siblingCompany->id, (int) $event->id))
        ->toThrow(TrainingEventNotFoundException::class);

    $otherTenant = createTenant(['name' => 'Other Training Tenant']);
    app(TenantContext::class)->set((int) $otherTenant->id);
    expect(fn () => $store->start((int) $fixture['company']->id, (int) $event->id))
        ->toThrow(TrainingEventNotFoundException::class);
});

test('a company merge carries the event and immutable audit history through its course owner', function (): void {
    $fixture = trainingEventFixture();
    $event = app(TrainingEventStore::class)->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $survivor = trainingEventEntity($fixture['tenantId'], 'company');

    $fixture['company']->update([
        'state' => WorkforceEntity::STATE_MERGED,
        'merged_into_entity_id' => $survivor->id,
        'merged_at' => now(),
    ]);
    $fixture['course']->movingCompany('Exercise the same ownership move performed by the canonical company merge.')
        ->fill(['company_entity_id' => $survivor->id])
        ->save();

    expect((int) $event->refresh()->company_entity_id)->toBe((int) $survivor->id)
        ->and(TrainingEventAuditEvent::query()->forCompany(
            $fixture['tenantId'],
            (int) $survivor->id,
        )->where('training_event_id', $event->id)->count())->toBe(1);
});

test('the actual register gives HR company scope, HOD department scope, and rejects grant all', function (): void {
    $fixture = trainingEventFixture();
    $store = app(TrainingEventStore::class);
    $operationsEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture));
    $financeEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'targetDepartmentEntityId' => $fixture['departments'][1],
        'organizerEmployeeEntityId' => (int) $fixture['finance']->workforce_entity_id,
        'venue' => 'Finance room',
    ]));
    $companyWideEvent = $store->schedule((int) $fixture['company']->id, trainingEventDraft($fixture, [
        'targetDepartmentEntityId' => null,
        'venue' => 'Company hall',
    ]));

    $hr = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $hod = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    $platformAdmin = User::factory()->create(['company_id' => $fixture['platformCompany']->id]);
    trainingEventRole($hr, 'people_hr');
    trainingEventRole($hod, 'people_hod');
    trainingEventRole($platformAdmin, 'core_admin');
    SkillActorBinding::query()->create([
        'tenant_id' => $fixture['tenantId'],
        'company_entity_id' => $fixture['company']->id,
        'platform_user_id' => $hod->id,
        'employee_entity_id' => $fixture['head']->workforce_entity_id,
        'user_entity_id' => $fixture['head']->user_entity_id,
        'confirmed_by_user_id' => $hr->id,
        'review_reference' => 'review:training-hod',
        'confirmed_at' => now(),
    ]);

    $this->actingAs($platformAdmin)
        ->get(route('people-connector.training.events.index'))
        ->assertForbidden();

    expect(app(TrainingAudience::class)->visibleEvents($hr, (int) $fixture['company']->id)->pluck('id')->all())
        ->toEqualCanonicalizing([(int) $operationsEvent->id, (int) $financeEvent->id, (int) $companyWideEvent->id])
        ->and(app(TrainingAudience::class)->visibleEvents($hod, (int) $fixture['company']->id)->pluck('id')->all())
        ->toEqualCanonicalizing([(int) $operationsEvent->id, (int) $companyWideEvent->id])
        ->and(app(TrainingAudience::class)->canManage($hod, (int) $fixture['company']->id))->toBeFalse()
        ->and(fn () => app(TrainingAudience::class)->allowedCompanies($platformAdmin))
        ->toThrow(AuthorizationDeniedException::class);

    Livewire::actingAs($hod)->test(Index::class)
        ->assertViewHas('events', fn ($events): bool => $events->pluck('id')->all() === [(int) $operationsEvent->id, (int) $companyWideEvent->id])
        ->assertDontSee('Finance Worker')
        ->assertDontSee('Finance room')
        ->assertSee('Company hall')
        ->assertSee('Company-wide')
        ->assertSee('Not recorded by the participant register yet');

    expect(fn () => Livewire::actingAs($hod)->test(Index::class)->call('start', (int) $operationsEvent->id))
        ->toThrow(AuthorizationDeniedException::class);

    $store->cancel((int) $fixture['company']->id, (int) $operationsEvent->id, 'Weather closure');
    Livewire::actingAs($hr)->test(Index::class)
        ->assertSee('Cancelled')
        ->assertSee('Weather closure');

});
