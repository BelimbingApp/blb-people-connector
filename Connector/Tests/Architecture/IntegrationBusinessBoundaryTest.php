<?php

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

it('prevents integration modules gaining Skill or Training imports', function (): void {
    // Temporary source-test dependencies, removed by blb-people-connector#121 (R4).
    // Pin file + class so an existing allowance cannot spread to another module.
    $allowed = [
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Data\SkillDraft', // #121
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod', // #121
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Enums\SkillScope', // #121
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Models\Skill', // #121
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Models\SkillCategory', // #121
        'Connector/Tests/Feature/CompanyIsolationContractTest.php:App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Data\ProficiencyLevelDraft', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Data\RequirementItemDraft', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Data\RequirementProfileDraft', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Data\RequirementSelectorDraft', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Data\SkillDraft', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Enums\RequirementCriticality', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Enums\RequirementProfileStatus', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Enums\SelectorType', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Enums\SkillScope', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Models\RequirementProfile', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Models\Skill', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Services\ProficiencyScaleStore', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Services\RequirementProfileStore', // #121
        'Connector/Tests/Feature/DataShareRoundTripTest.php:App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Data\SkillDraft', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Enums\AssessmentMethod', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Enums\SkillScope', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Models\RequirementProfileSelector', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Models\Skill', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Models\SkillActorBinding', // #121
        'Connector/Tests/Feature/WorkforceReferenceContractTest.php:App\Domains\PeopleConnector\Skill\Services\SkillCatalogStore', // #121
    ];

    $root = dirname(__DIR__, 3);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
    $imported = [];
    $unexpected = [];

    foreach (['Connector', 'FirstPartyPeople'] as $module) {
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
                    if (! str_starts_with(strtolower($class), 'app\\domains\\peopleconnector\\skill\\')
                        && ! str_starts_with(strtolower($class), 'app\\domains\\peopleconnector\\training\\')) {
                        continue;
                    }

                    $dependency = substr($file->getPathname(), strlen($root) + 1).':'.$class;
                    $imported[] = $dependency;
                    if (! in_array($dependency, $allowed, true)) {
                        $unexpected[] = substr($file->getPathname(), strlen($root) + 1).':'.$use->getStartLine().' imports '.$class;
                    }
                }
            }
        }
    }

    sort($unexpected);
    $stale = array_diff($allowed, $imported);
    $this->assertSame([], $unexpected, "New business-module coupling:\n".implode("\n", $unexpected));
    $this->assertSame([], array_values($stale), "Remove stale R4 allowances:\n".implode("\n", $stale));
});
