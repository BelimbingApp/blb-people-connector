<?php

use App\Base\Database\DTO\DataShare\DataShareExportResult;
use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Models\DataShareReceipt;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataShareImportPlanner;
use App\Base\Database\Services\DataShare\DataSharePackageApplier;
use App\Base\Database\Services\DataShare\DataSharePackageExporter;
use App\Base\Database\Services\DataShare\DataSharePackageInbox;
use App\Base\Database\Services\DataShare\DataSharePackageReader;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ProviderAuthenticationRequest;
use App\Domains\PeopleConnector\Connector\Data\ProviderScope;
use App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException;
use App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore;
use App\Domains\PeopleConnector\Connector\Services\ProviderCredentialStore;
use App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract;
use App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant;
use App\Domains\PeopleConnector\Skill\Data\SkillDraft;
use App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod;
use App\Domains\PeopleConnector\Skill\Enums\SkillScope;
use App\Domains\PeopleConnector\Skill\Models\Skill;
use App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The platform's DataShare packages are the export/backup/restore vehicle
 * for connector-owned data (blb-people-connector#53): the connector's tables
 * are registered with their module path, and the scope catalog derives two
 * scopes from that. Nothing had ever driven the vehicle over these tables.
 * These tests do, on both drivers, with two companies in one tenant.
 */
const PEOPLE_CONNECTOR_SHARE_SCOPE = 'app/Domains/PeopleConnector/Connector';
const PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE = 'app/Domains/PeopleConnector/Skill';

beforeEach(function (): void {
    // Packages are real files; keep them out of storage/app/private like the
    // platform's own DataShare tests do.
    Storage::fake('local');
});

afterEach(function (): void {
    app(TenantContext::class)->clear();
});

function connectorShareBecome(string $id, string $role): void
{
    $settings = app(SettingsService::class);
    $settings->set('data_share.instance.id', $id);
    $settings->set('data_share.instance.name', $id);
    $settings->set('data_share.instance.role', $role);
}

/**
 * @param  array<string, list<string>>  $redactions  operator-chosen columns whose values leave as null (belimbing#530)
 * @return array{bundle: DataShareTransferOfferBundle, export: DataShareExportResult}
 */
function connectorSharePublish(string $scope, array $redactions = []): array
{
    connectorShareBecome('connector-source-dev', 'development');
    $tables = array_column(app(DataShareScopeCatalog::class)->scope($scope)->tables, 'table');
    $preview = app(DataSharePackageExporter::class)->preview($scope, $tables, $redactions);
    $bundle = app(DataShareTransferOfferManager::class)->publish($scope, $tables, $preview->previewHash, actorId: 9001, redactions: $redactions);
    $offer = DataShareTransferOffer::query()->where('offer_id', $bundle->offerId)->firstOrFail();
    $stream = Storage::disk('local')->readStream($offer->package_path);

    try {
        $manifest = app(DataSharePackageReader::class)->manifest($stream);
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    return [
        'bundle' => $bundle,
        'export' => new DataShareExportResult($offer->package_id, $offer->package_path, $offer->package_sha256, $offer->bytes, $manifest),
    ];
}

function connectorShareReceive(DataShareTransferOfferBundle $bundle, DataShareExportResult $export): DataShareReceipt
{
    connectorShareBecome('connector-destination-stage', 'staging');

    return app(DataSharePackageInbox::class)->receiveFromProtectedPath($export->path, DataSharePackageExpectation::fromOffer($bundle));
}

/**
 * Two companies in one tenant, each with a category and a skill, so a leak
 * across the company axis is visible in what leaves and what comes back.
 *
 * @return array{TwoCompanyTenant, Skill, Skill}
 */
function connectorShareFixture(): array
{
    $fixture = CompanyIsolationContract::twoCompaniesInOneTenant();
    app(TenantContext::class)->set($fixture->tenantId);
    $catalog = app(SkillCatalogStore::class);
    $skills = [];

    foreach ([[$fixture->alphaCompanyEntityId, 'alpha'], [$fixture->betaCompanyEntityId, 'beta']] as [$company, $prefix]) {
        $category = $catalog->defineCategory($company, "{$prefix}.safety", ucfirst($prefix).' Safety');
        $skills[] = $catalog->defineSkill($company, new SkillDraft(
            code: "{$prefix}.forklift", name: ucfirst($prefix).' Forklift', definition: 'Operates the line to the approved standard.',
            categoryId: (int) $category->id, scope: SkillScope::Shared, criticalClassification: null, evidenceGuide: null,
            defaultAssessmentMethod: AssessmentMethod::DirectObservation, defaultReassessmentMonths: 12,
        ));
    }

    return [$fixture, $skills[0], $skills[1]];
}

/** @return array<string, list<array<string, mixed>>> every row of every table in the scope, keyed by table, ordered by id */
function connectorShareSnapshot(string $scope): array
{
    $rows = [];

    foreach (array_column(app(DataShareScopeCatalog::class)->scope($scope)->tables, 'table') as $table) {
        $rows[$table] = array_map(fn (object $row): array => (array) $row, DB::table($table)->orderBy('id')->get()->all());
    }

    return $rows;
}

test('both connector scopes export every row faithfully: a re-plan against the source reports nothing but unchanged', function (string $scope): void {
    connectorShareFixture();
    $before = connectorShareSnapshot($scope);
    $rowCount = array_sum(array_map('count', $before));
    expect($rowCount)->toBeGreaterThan(0);

    ['bundle' => $bundle, 'export' => $export] = connectorSharePublish($scope);
    $plan = app(DataShareImportPlanner::class)->plan(connectorShareReceive($bundle, $export));

    // "unchanged" is matched by key and content, so this is the package
    // representing every row of every table in the scope identically.
    expect($plan->status)->toBe('ready')
        ->and($plan->summary['counts'])->toBe(['insert' => 0, 'unchanged' => $rowCount, 'conflict' => 0]);
})->with([PEOPLE_CONNECTOR_SHARE_SCOPE, PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE]);

test('the skill scope restores into an emptied catalog identically, with the company guard and the skills-table triggers still standing', function (): void {
    [$fixture, $alphaSkill, $betaSkill] = connectorShareFixture();
    $before = connectorShareSnapshot(PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE);
    ['bundle' => $bundle, 'export' => $export] = connectorSharePublish(PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE);

    // Empty the catalog the way a fresh instance would be: raw, children first.
    foreach (['people_connector_skill_proficiency_scale_levels', 'people_connector_skill_proficiency_scales', 'people_connector_skill_skills', 'people_connector_skill_categories'] as $table) {
        DB::table($table)->delete();
    }
    expect(array_sum(array_map('count', connectorShareSnapshot(PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE))))->toBe(0);

    $receipt = connectorShareReceive($bundle, $export);
    $plan = app(DataShareImportPlanner::class)->plan($receipt);
    expect($plan->summary['counts']['conflict'])->toBe(0)
        ->and($plan->summary['counts']['insert'])->toBe(array_sum(array_map('count', $before)));
    app(DataSharePackageApplier::class)->apply($plan, $receipt->package_sha256, $plan->plan_hash, confirmed: true);

    expect(connectorShareSnapshot(PEOPLE_CONNECTOR_SKILL_SHARE_SCOPE))->toEqual($before);

    // Restored rows are still company-owned rows. The guard is the model's,
    // not the package's: a query that pins no company is refused outright
    // (deleting RequireCompanyScope from CompanyOwned turns this red — the
    // forCompany() assertions alone stayed green without it), and pinned to
    // one company the other's rows are invisible.
    expect(fn () => Skill::query()->forTenant($fixture->tenantId)->get())->toThrow(MissingCompanyScopeException::class);
    expect(Skill::query()->forCompany($fixture->tenantId, $fixture->alphaCompanyEntityId)->pluck('code')->all())->toBe(['alpha.forklift'])
        ->and(Skill::query()->forCompany($fixture->tenantId, $fixture->betaCompanyEntityId)->pluck('code')->all())->toBe(['beta.forklift']);

    // The database guards on the skills table — the skill-code trigger and
    // the company-owner trigger — are the migration's, not the package's.
    // The scale and level guards are not exercised here: this fixture
    // creates no scales.
    expect(fn () => DB::transaction(fn () => DB::table('people_connector_skill_skills')->where('id', $alphaSkill->id)->update(['code' => 'renamed'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('people_connector_skill_skills')->where('id', $betaSkill->id)->update(['company_entity_id' => $fixture->alphaCompanyEntityId])))
        ->toThrow(QueryException::class);
});

test('a scope export is instance-level: one package carries every company in the tenant, and what the credential row carries is measured', function (): void {
    [$fixture] = connectorShareFixture();
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->active(ProviderScope::company((int) $fixture->alphaCompany->id));
    $issuedAt = new DateTimeImmutable('2026-09-02T00:00:00+00:00');
    app(ProviderCredentialStore::class)->issue(
        new ProviderAuthenticationRequest($fixture->tenantId, (int) $connection->id, 'blb-people-connector', ['employee_directory:read']),
        $connection,
        'key-2026-09',
        'base-integration:alpha-secret-reference',
        $issuedAt,
        $issuedAt->modify('+5 minutes'),
    );

    ['bundle' => $bundle, 'export' => $export] = connectorSharePublish(PEOPLE_CONNECTOR_SHARE_SCOPE);

    // Read the package itself, not a description of it.
    $records = [];
    $stream = Storage::disk('local')->readStream($export->path);

    try {
        app(DataSharePackageReader::class)->inspect($stream, function ($scope, $table, array $record) use (&$records): void {
            $records[$table->table][] = $record['values'];
        });
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    $credential = $records['people_connector_connector_provider_credentials'][0] ?? null;

    // Measured, not designed. There is no company axis on a package: both
    // companies' rows leave together. The credential row leaves with them
    // and nothing in it is redacted: the platform's ColumnRedactor applies
    // only to diagnostic capture ("bulk exports preserve selected tables
    // exactly", data_share.php), and the table registry has no way for a
    // module to mark a table non-transferable. So the credential *reference*
    // travels in clear. Each fact is asserted so that a change in either
    // direction — the platform starting to redact or exclude, or this table
    // gaining a column — fails here instead of in an export nobody read.
    expect(count($records['people_connector_connector_workforce_companies'] ?? []))->toBe(2)
        ->and($credential)->not->toBeNull()
        // When this fails with null, belimbing#530 has landed (the platform now
        // redacts or excludes this table): update the contract section and this
        // assertion deliberately. It is a notification, not a break.
        ->and($credential['secret_reference'] ?? null)->toBe('base-integration:alpha-secret-reference', 'secret_reference changed: is belimbing#530 fixed? Update docs/contracts/company-ownership.md and this assertion.')
        ->and($credential['key_id'] ?? null)->toBe('key-2026-09')
        ->and(array_keys($credential))->toBe([
            'audience', 'connection_id', 'created_at', 'credential_id', 'expires_at', 'id', 'issued_at',
            'key_id', 'provider_id', 'revoked_at', 'scopes', 'secret_reference', 'tenant_id', 'updated_at',
        ]);

    // And because nothing was altered, the package re-plans as unchanged
    // against its source: it is a faithful copy, secrets references included.
    $plan = app(DataShareImportPlanner::class)->plan(connectorShareReceive($bundle, $export));
    expect($plan->actions()->where('table_name', 'people_connector_connector_provider_credentials')->value('action'))->toBe('unchanged');
});

test('an operator can redact the credential reference on export, and the platform names what that costs', function (): void {
    [$fixture] = connectorShareFixture();
    $connections = app(ProviderConnectionStore::class);
    $connection = $connections->active(ProviderScope::company((int) $fixture->alphaCompany->id));
    $issuedAt = new DateTimeImmutable('2026-09-02T00:00:00+00:00');
    app(ProviderCredentialStore::class)->issue(
        new ProviderAuthenticationRequest($fixture->tenantId, (int) $connection->id, 'blb-people-connector', ['employee_directory:read']),
        $connection,
        'key-2026-09',
        'base-integration:alpha-secret-reference',
        $issuedAt,
        $issuedAt->modify('+5 minutes'),
    );
    $table = 'people_connector_connector_provider_credentials';

    // The platform's preview suggests the two columns whose names match its
    // pattern, ticks nothing, and — once the operator ticks
    // secret_reference — says exactly what this repository measured for
    // #53: the column is NOT NULL, so every credential row becomes
    // unrestorable at the destination.
    connectorShareBecome('connector-source-dev', 'development');
    $tables = array_column(app(DataShareScopeCatalog::class)->scope(PEOPLE_CONNECTOR_SHARE_SCOPE)->tables, 'table');
    $preview = app(DataSharePackageExporter::class)->preview(PEOPLE_CONNECTOR_SHARE_SCOPE, $tables, [$table => ['secret_reference']]);
    $columns = collect($preview->advisories[$table])->keyBy('name');

    expect($columns['secret_reference']['suggested'])->toBeTrue()
        ->and($columns['credential_id']['suggested'])->toBeTrue()
        ->and($columns['key_id']['suggested'])->toBeFalse()
        ->and($columns['secret_reference']['redacted'])->toBeTrue()
        ->and($columns['secret_reference']['level'])->toBe('unrestorable')
        ->and($columns['secret_reference']['message'])->toContain('1 rows')->toContain($table);

    // With the redaction chosen, the reference does not leave, and the
    // package re-plans the credential row as a conflict against its source.
    ['bundle' => $bundle, 'export' => $export] = connectorSharePublish(PEOPLE_CONNECTOR_SHARE_SCOPE, [$table => ['secret_reference']]);
    $records = [];
    $stream = Storage::disk('local')->readStream($export->path);

    try {
        app(DataSharePackageReader::class)->inspect($stream, function ($scope, $tableDefinition, array $record) use (&$records): void {
            $records[$tableDefinition->table][] = $record['values'];
        });
    } finally {
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    // Presence and value asserted separately: `?? 'missing'` would read a
    // present-but-null value (the redacted one) as absent.
    expect((array) $export->manifest['redactions'])->toBe([$table => ['secret_reference']])
        ->and(array_key_exists('secret_reference', $records[$table][0]))->toBeTrue()
        ->and($records[$table][0]['secret_reference'])->toBeNull()
        ->and($records[$table][0]['key_id'] ?? null)->toBe('key-2026-09');

    $plan = app(DataShareImportPlanner::class)->plan(connectorShareReceive($bundle, $export));
    expect($plan->actions()->where('table_name', $table)->value('action'))->toBe('conflict');
});
