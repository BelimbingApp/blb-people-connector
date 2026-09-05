<?php

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

it('pins the Connector imports that Skill and Training must unwind', function (): void {
    // This is the relocation baseline, including test dependencies. Remove entries
    // as their last import disappears; new dependencies require an explicit review.
    $allowed = [
        'App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities',
        'App\Domains\PeopleConnector\Connector\Data\ExternalReference',
        'App\Domains\PeopleConnector\Connector\Data\ProviderScope',
        'App\Domains\PeopleConnector\Connector\Data\WorkforceCompany',
        'App\Domains\PeopleConnector\Connector\Data\WorkforceEmployee',
        'App\Domains\PeopleConnector\Connector\Data\WorkforceOrganizationUnit',
        'App\Domains\PeopleConnector\Connector\Data\WorkforcePosition',
        'App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance',
        'App\Domains\PeopleConnector\Connector\Data\WorkforceReference',
        'App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType',
        'App\Domains\PeopleConnector\Connector\Exceptions\CompanyMoveRefusedException',
        'App\Domains\PeopleConnector\Connector\Exceptions\MissingCompanyScopeException',
        'App\Domains\PeopleConnector\Connector\Exceptions\WorkforceMergeConflictException',
        'App\Domains\PeopleConnector\Connector\Models\CompanyOwnedModels',
        'App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned',
        'App\Domains\PeopleConnector\Connector\Models\DomainModels',
        'App\Domains\PeopleConnector\Connector\Models\ExternalIdentity',
        'App\Domains\PeopleConnector\Connector\Models\ProviderConnection',
        'App\Domains\PeopleConnector\Connector\Models\TenantOwnedModel',
        'App\Domains\PeopleConnector\Connector\Models\WorkforceCompanyProjection',
        'App\Domains\PeopleConnector\Connector\Models\WorkforceEmployeeProjection',
        'App\Domains\PeopleConnector\Connector\Models\WorkforceEntity',
        'App\Domains\PeopleConnector\Connector\Models\WorkforceOrganizationUnitProjection',
        'App\Domains\PeopleConnector\Connector\Models\WorkforcePositionProjection',
        'App\Domains\PeopleConnector\Connector\Services\CompanyAttribution',
        'App\Domains\PeopleConnector\Connector\Services\ProviderConnectionStore',
        'App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore',
        'App\Domains\PeopleConnector\Connector\Services\WorkforceProjectionStore',
        'App\Domains\PeopleConnector\Connector\Testing\CompanyIsolationContract',
        'App\Domains\PeopleConnector\Connector\Testing\TwoCompanyTenant',
    ];

    $root = dirname(__DIR__, 3);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $imported = [];
    $unexpected = [];

    foreach (['Skill', 'Training'] as $module) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$module));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $statements = $parser->parse(file_get_contents($file->getPathname()));
            $imports = $finder->find($statements, fn (Node $node): bool => $node instanceof Use_ || $node instanceof GroupUse);

            foreach ($imports as $import) {
                foreach ($import->uses as $use) {
                    $type = $use->type === Use_::TYPE_UNKNOWN ? $import->type : $use->type;
                    if ($type !== Use_::TYPE_NORMAL) {
                        continue;
                    }

                    $class = ($import instanceof GroupUse ? $import->prefix.'\\' : '').$use->name;
                    if (! str_starts_with($class, 'App\Domains\PeopleConnector\Connector\\')) {
                        continue;
                    }

                    $imported[] = $class;
                    if (! in_array($class, $allowed, true)) {
                        $unexpected[] = substr($file->getPathname(), strlen($root) + 1).':'.$use->getStartLine().' imports '.$class;
                    }
                }
            }
        }
    }

    sort($unexpected);
    $stale = array_diff($allowed, $imported);
    $this->assertSame([], $unexpected, "New Connector coupling:\n".implode("\n", $unexpected));
    $this->assertSame([], array_values($stale), "Allowlist classes no longer imported:\n".implode("\n", $stale));
});
