<?php

use App\Base\Tenancy\Contracts\TenantContext;
use App\Core\Employee\Models\Employee;
use App\Domains\People\Provider\Data\WorkforceSubject;
use App\Domains\People\Provider\Enums\WorkforceResourceType as SubjectResourceType;
use App\Domains\People\Provider\Enums\WorkforceSubjectRefusal;
use App\Domains\People\Provider\Services\NativeWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Services\ProjectionWorkforceSubjectResolver;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\SynchronizedWorkforce;

/**
 * The People denial parity matrix (docs/contracts/denial-parity.md in the
 * composed People domain) run as a suite, plan 0007-b (blb-people#192).
 *
 * A row is executable here only when the operation has a second,
 * projection-side implementation to hold to the native one. The executor map
 * below is the authority for which rows those are: the matrix names the pair
 * in its Projection path column, and the two must agree. Every other row is a
 * single-implementation Livewire command or native service; it is skipped with
 * its reason and counted, so the printed tally makes the executed/skipped
 * split visible in CI rather than hiding it inside one green test.
 */
const DENIAL_PARITY_MATRIX = 'app/Domains/People/docs/contracts/denial-parity.md';

/**
 * @return array{columns: array<string, int>, rows: list<array<string, string>>}
 */
function denialParityMatrix(): array
{
    $matrix = file_get_contents(base_path(DENIAL_PARITY_MATRIX));
    expect($matrix)->not->toBeFalse();

    $lines = collect(preg_split('/\R/', $matrix))
        ->filter(fn (string $line): bool => str_starts_with($line, '|'))
        ->map(fn (string $line): array => array_map('trim', explode('|', trim($line, '|'))))
        ->values();

    $header = $lines->first(fn (array $cells): bool => $cells[0] === 'Module');
    expect($header)->not->toBeNull();
    $columns = array_flip($header);

    $rows = $lines
        ->filter(fn (array $cells): bool => count($cells) === count($header) && $cells[0] !== 'Module' && $cells[0] !== '---')
        ->map(fn (array $cells): array => array_combine($header, $cells))
        ->values()
        ->all();

    expect($rows)->not->toBeEmpty();

    return ['columns' => $columns, 'rows' => $rows];
}

/**
 * Executors keyed by "Module / Business operation". Each returns, per denial
 * axis the matrix marks covered, the same scenario expressed in both id
 * spaces: [native subject args, projection subject args, expected refusal].
 *
 * @return array<string, Closure(): array<string, array{0: array, 1: array, 2: ?WorkforceSubjectRefusal}>>
 */
function denialParityExecutors(): array
{
    return [
        'Provider / Resolve a workforce subject' => function (): array {
            $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
            $elsewhere = CompanyIsolationContract::twoCompaniesInOneTenant('Far Alpha', 'Far Beta');
            $employee = SubjectResourceType::Employee->value;

            $nativeHere = Employee::factory()->create(['company_id' => $fixture->alphaCompany->id, 'status' => 'active']);
            $nativeSibling = Employee::factory()->create(['company_id' => $fixture->betaCompany->id, 'status' => 'active']);
            $nativeElsewhere = Employee::factory()->create(['company_id' => $elsewhere->alphaCompany->id, 'status' => 'active']);
            $projectedHere = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->alphaCompanyEntityId);
            $projectedSibling = SynchronizedWorkforce::inCompany($fixture->tenantId, $fixture->betaCompanyEntityId);
            $projectedElsewhere = SynchronizedWorkforce::inCompany($elsewhere->tenantId, $elsewhere->alphaCompanyEntityId);

            app(TenantContext::class)->set($fixture->tenantId);

            return [
                // The fixtures must resolve before any denial counts: a deleted
                // guard cannot hide behind a fixture that never resolved.
                'Resolved' => [
                    [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeHere->getKey()],
                    [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedHere[$employee]],
                    null,
                ],
                'Wrong tenant' => [
                    [$elsewhere->tenantId, $elsewhere->alphaCompany->id, (string) $nativeElsewhere->getKey()],
                    [$elsewhere->tenantId, $elsewhere->alphaCompanyEntityId, (string) $projectedElsewhere[$employee]],
                    WorkforceSubjectRefusal::Unknown,
                ],
                'Wrong company' => [
                    [$fixture->tenantId, $fixture->alphaCompany->id, (string) $nativeSibling->getKey()],
                    [$fixture->tenantId, $fixture->alphaCompanyEntityId, (string) $projectedSibling[$employee]],
                    WorkforceSubjectRefusal::WrongCompany,
                ],
            ];
        },
    ];
}

test('every matrix row with a projection path refuses the same way on both resolvers, and the rest are skipped with a reason', function (): void {
    ['columns' => $columns, 'rows' => $rows] = denialParityMatrix();
    $executors = denialParityExecutors();
    $hasProjectionColumn = array_key_exists('Projection path', $columns);

    $executed = [];
    $skipped = [];

    foreach ($rows as $row) {
        $key = $row['Module'].' / '.$row['Business operation'];
        $projectionPath = $hasProjectionColumn ? $row['Projection path'] : null;
        $executor = $executors[$key] ?? null;

        if ($projectionPath !== null && $projectionPath !== 'missing') {
            expect($executor)->not->toBeNull("{$key} names a projection path but this suite has no executor for it");
        }

        if ($executor === null) {
            $skipped[$key] = 'single implementation, no projection path';

            continue;
        }

        if ($projectionPath === 'missing') {
            test()->fail("{$key} has an executor here but the matrix records its projection path as missing");
        }

        $scenarios = $executor();
        $axes = ['Wrong tenant', 'Wrong company', 'Missing capability', 'Unauthorized actor'];

        foreach (['Resolved', ...$axes] as $axis) {
            if ($axis !== 'Resolved' && $row[$axis] !== 'covered') {
                $skipped["{$key} / {$axis}"] = 'matrix marks this axis missing';

                continue;
            }

            expect(array_key_exists($axis, $scenarios))->toBeTrue("{$key} covers {$axis} in the matrix but the executor has no scenario for it");
            [$native, $projected, $expected] = $scenarios[$axis];

            $nativeResolution = app(NativeWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
                $native[0], $native[1], SubjectResourceType::Employee, $native[2],
            ));
            $projectedResolution = app(ProjectionWorkforceSubjectResolver::class)->resolve(new WorkforceSubject(
                $projected[0], $projected[1], SubjectResourceType::Employee, $projected[2],
            ));

            expect($nativeResolution->refusal)->toBe($expected, "{$key} / {$axis}: native refusal")
                ->and($projectedResolution->refusal)->toBe($expected, "{$key} / {$axis}: projection refusal")
                ->and($projectedResolution->record === null)->toBe($nativeResolution->record === null, "{$key} / {$axis}: record presence");

            $executed[] = "{$key} / {$axis}";
        }
    }

    $skippedRows = array_filter($skipped, fn (string $key): bool => ! str_contains($key, ' / ') || substr_count($key, ' / ') === 1, ARRAY_FILTER_USE_KEY);
    $skippedRows = array_filter($skippedRows, fn (string $key): bool => ! isset($executors[$key]), ARRAY_FILTER_USE_KEY);
    $summary = sprintf(
        "denial parity: %d row(s) executed (%d checks), %d row(s) skipped as single-implementation, %d axis check(s) skipped as missing in the matrix%s\n",
        count($executors),
        count($executed),
        count($skippedRows),
        count($skipped) - count($skippedRows),
        $hasProjectionColumn ? '' : ' (matrix at this People pin predates the Projection path column)',
    );
    fwrite(STDERR, $summary);

    // The pair count is pinned: a new projection-side implementation cannot
    // land without an executor and a matrix row recording it.
    expect(array_keys($executors))->toBe(['Provider / Resolve a workforce subject'])
        ->and($executed)->toBe([
            'Provider / Resolve a workforce subject / Resolved',
            'Provider / Resolve a workforce subject / Wrong tenant',
            'Provider / Resolve a workforce subject / Wrong company',
        ])
        ->and(count($rows))->toBeGreaterThan(count($executors));
});
