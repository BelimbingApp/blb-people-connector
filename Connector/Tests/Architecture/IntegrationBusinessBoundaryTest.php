<?php

use PhpParser\Node;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

it('ships only integration modules after the People business-module relocation', function (): void {
    $root = dirname(__DIR__, 3);
    $moduleIds = [];

    foreach (glob($root.'/*/composer.json') as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $moduleIds[] = $manifest['extra']['blb']['module'] ?? null;
    }

    sort($moduleIds);

    expect(is_dir($root.'/Skill'))->toBeFalse()
        ->and(is_dir($root.'/Training'))->toBeFalse()
        ->and($moduleIds)->toBe([
            'people-connector/connector',
            'people-connector/first-party-people',
        ]);
});

it('prevents integration modules gaining Skill or Training imports', function (): void {
    $root = dirname(__DIR__, 3);
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;
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

                    $unexpected[] = substr($file->getPathname(), strlen($root) + 1).':'.$use->getStartLine().' imports '.$class;
                }
            }
        }
    }

    sort($unexpected);
    $this->assertSame([], $unexpected, "New business-module coupling:\n".implode("\n", $unexpected));
});
