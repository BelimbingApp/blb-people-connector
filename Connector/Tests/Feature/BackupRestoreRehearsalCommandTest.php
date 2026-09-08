<?php

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\DTO\AuthorizationDecision;
use App\Base\Authz\DTO\ResourceContext;
use App\Base\Authz\Enums\AuthorizationReasonCode;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Contracts\PublishesBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Contracts\RestoresBackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\BackupRestorePackage;
use App\Domains\PeopleConnector\Connector\Data\ScratchRestoreResult;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;

afterEach(fn () => app(TenantContext::class)->clear());

/** @return array{tenant_id: int, operator: User} */
function backupRehearsalOperator(string $name = 'Backup rehearsal'): array
{
    [$tenant, $company] = createTenantWithCompany(['name' => $name]);

    return [
        'tenant_id' => (int) $tenant->id,
        'operator' => User::factory()->create(['company_id' => $company->id]),
    ];
}

function backupRehearsalAuthorize(bool $allowed = true): object
{
    $authorization = new class($allowed) implements AuthorizationService
    {
        /** @var list<array{int|string|null, string}> */
        public array $calls = [];

        public function __construct(public bool $allowed) {}

        public function can(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): AuthorizationDecision
        {
            return $this->allowed
                ? AuthorizationDecision::allow()
                : AuthorizationDecision::deny(AuthorizationReasonCode::DENIED_MISSING_CAPABILITY);
        }

        public function authorize(Actor $actor, string $capability, ?ResourceContext $resource = null, array $context = []): void
        {
            $this->calls[] = [$actor->id, $capability];

            if (! $this->allowed) {
                throw new ProviderAuthorizationException('connector', 'backup_restore_rehearsal', 'The operator cannot rehearse connector backup restoration.');
            }
        }

        public function filterAllowed(Actor $actor, string $capability, iterable $resources, array $context = []): Collection
        {
            return collect($resources);
        }
    };
    app()->instance(AuthorizationService::class, $authorization);

    return $authorization;
}

/**
 * @param  array<string, int>  $source
 * @param  array<string, int>  $scratchBefore
 * @param  array<string, int>  $scratchAfter
 * @return array{publisher: object, restorer: object}
 */
function backupRehearsalFakes(array $source, array $scratchBefore, array $scratchAfter): array
{
    $publisher = new class($source) implements PublishesBackupRestorePackage
    {
        public int $calls = 0;

        /** @param array<string, int> $counts */
        public function __construct(private readonly array $counts) {}

        public function publish(int $tenantId, int $operatorId): BackupRestorePackage
        {
            $this->calls++;

            return new BackupRestorePackage(
                tenantId: $tenantId,
                tables: array_keys($this->counts),
                sourceCounts: $this->counts,
                redactions: ['people_connector_connector_provider_credentials' => ['secret_reference: unrestorable when redacted']],
                protectedPackagePath: 'data-share/outgoing/test-package.zip',
                offerJson: '{"format":"test"}',
            );
        }
    };
    $restorer = new class($scratchBefore, $scratchAfter) implements RestoresBackupRestorePackage
    {
        public int $calls = 0;

        /**
         * @param  array<string, int>  $before
         * @param  array<string, int>  $after
         */
        public function __construct(private readonly array $before, private readonly array $after) {}

        public function restore(BackupRestorePackage $package, int $operatorId): ScratchRestoreResult
        {
            $this->calls++;

            return new ScratchRestoreResult($this->before, $this->after);
        }
    };
    app()->instance(PublishesBackupRestorePackage::class, $publisher);
    app()->instance(RestoresBackupRestorePackage::class, $restorer);

    return ['publisher' => $publisher, 'restorer' => $restorer];
}

function backupRehearsalCall(array $fixture, array $extra = []): int
{
    app(TenantContext::class)->clear();

    return Artisan::call('connector:backup:rehearse', [
        '--tenant' => $fixture['tenant_id'],
        '--as' => $fixture['operator']->id,
        ...$extra,
    ]);
}

test('a restored row-count mismatch is reported per table and exits non-zero', function (): void {
    $fixture = backupRehearsalOperator();
    $authorization = backupRehearsalAuthorize();
    $fakes = backupRehearsalFakes(
        ['people_connector_connector_provider_connections' => 1, 'people_connector_connector_external_identities' => 2],
        ['people_connector_connector_provider_connections' => 0, 'people_connector_connector_external_identities' => 0],
        ['people_connector_connector_provider_connections' => 1, 'people_connector_connector_external_identities' => 1],
    );

    expect(backupRehearsalCall($fixture, ['--json' => true]))->toBe(1);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($report['successful'])->toBeFalse()
        ->and($report['mismatches'])->toBe([
            'people_connector_connector_external_identities' => ['source' => 2, 'restored' => 1],
        ])
        ->and($report['source_counts'])->toBe([
            'people_connector_connector_external_identities' => 2,
            'people_connector_connector_provider_connections' => 1,
        ])
        ->and($report['scratch_before'])->toBe([
            'people_connector_connector_external_identities' => 0,
            'people_connector_connector_provider_connections' => 0,
        ])
        ->and($report['redactions'])->toHaveKey('people_connector_connector_provider_credentials')
        ->and($authorization->calls)->toBe([[(int) $fixture['operator']->id, 'people-connector.connection.manage']])
        ->and($fakes['publisher']->calls)->toBe(1)
        ->and($fakes['restorer']->calls)->toBe(1);
});

test('an empty scratch restore with matching per-table counts exits zero', function (): void {
    $fixture = backupRehearsalOperator();
    backupRehearsalAuthorize();
    $counts = ['people_connector_connector_external_identities' => 2, 'people_connector_connector_provider_connections' => 1];
    backupRehearsalFakes($counts, array_fill_keys(array_keys($counts), 0), $counts);

    expect(backupRehearsalCall($fixture, ['--json' => true]))->toBe(0);
    $report = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    expect($report['successful'])->toBeTrue()
        ->and($report['mismatches'])->toBe([])
        ->and($report['scratch_after'])->toBe($counts);
});

test('a named operator without the rehearsal grant is refused before export', function (): void {
    $fixture = backupRehearsalOperator();
    backupRehearsalAuthorize(false);
    $fakes = backupRehearsalFakes([], [], []);

    expect(backupRehearsalCall($fixture))->toBe(1)
        ->and(Artisan::output())->toContain('cannot rehearse connector backup restoration')
        ->and($fakes['publisher']->calls)->toBe(0)
        ->and($fakes['restorer']->calls)->toBe(0);
});

test('an operator from another tenant is refused before authorization or export', function (): void {
    $fixture = backupRehearsalOperator('Source tenant');
    $other = backupRehearsalOperator('Other tenant');
    $fixture['operator'] = $other['operator'];
    $authorization = backupRehearsalAuthorize();
    $fakes = backupRehearsalFakes([], [], []);

    expect(backupRehearsalCall($fixture))->toBe(1)
        ->and(Artisan::output())->toContain('inside the operator\'s own tenant')
        ->and($authorization->calls)->toBe([])
        ->and($fakes['publisher']->calls)->toBe(0)
        ->and($fakes['restorer']->calls)->toBe(0);
});
