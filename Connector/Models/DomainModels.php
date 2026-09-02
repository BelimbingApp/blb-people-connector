<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Contracts\ReferencesWorkforceEntities;
use App\Domains\PeopleConnector\Connector\Data\WorkforceReference;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use Illuminate\Database\Eloquent\Model;

/**
 * Every Eloquent model in this domain, found by looking rather than by being
 * told, and the two views of it that derive rules from declarations:
 * company ownership (CompanyOwnedModels) and inbound workforce references.
 *
 * Resolved once per process: the scan is a directory walk plus a regex per
 * file, cheap enough for the admin-frequency operations that need it and
 * far too slow to repeat per query.
 *
 * Model files are found by directory rather than by declaration. A model
 * placed outside a `Models` directory enrols itself into nothing, silently.
 * That is a deliberate trade — parsing every file in the domain to find
 * Eloquent subclasses costs more than it buys while the house layout puts
 * models in `Models` — but it is a real limit, and the "the repository
 * actually contains company-owned models" test only catches total discovery
 * failure, not one missed model.
 */
final class DomainModels
{
    /** @var list<class-string<Model>>|null */
    private static ?array $discovered = null;

    /**
     * @return list<class-string<Model>>
     */
    public static function all(): array
    {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

        $models = [];

        foreach (self::modelFiles() as $file) {
            $class = self::classIn($file);

            if ($class === null || ! class_exists($class)) {
                continue;
            }

            if (is_subclass_of($class, Model::class) && ! (new \ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        sort($models);

        return self::$discovered = array_values(array_unique($models));
    }

    /**
     * Every declared reference to a workforce entity of the given type, as
     * [model, reference] pairs — what a merge of such an entity must rewrite.
     *
     * @return list<array{class-string<Model>, WorkforceReference}>
     */
    public static function referencing(WorkforceResourceType $type): array
    {
        $pairs = [];

        foreach (self::all() as $model) {
            if (! is_subclass_of($model, ReferencesWorkforceEntities::class)) {
                continue;
            }

            foreach ((new $model)->workforceReferences() as $reference) {
                if ($reference->type === $type) {
                    $pairs[] = [$model, $reference];
                }
            }
        }

        return $pairs;
    }

    /**
     * Forget the scan, for a test that adds a model and needs to see it join.
     */
    public static function forget(): void
    {
        self::$discovered = null;
    }

    /** @return list<\SplFileInfo> */
    private static function modelFiles(): array
    {
        $domainRoot = dirname(__DIR__, 2);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($domainRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && str_contains(str_replace('\\', '/', $file->getPath()), '/Models')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return class-string|null */
    private static function classIn(\SplFileInfo $file): ?string
    {
        $source = (string) file_get_contents($file->getPathname());

        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            return null;
        }

        if (preg_match('/^(?:final\s+|abstract\s+)?(?:readonly\s+)?class\s+(\w+)/m', $source, $class) !== 1) {
            return null;
        }

        /** @var class-string $fqcn */
        $fqcn = trim($namespace[1]).'\\'.$class[1];

        return $fqcn;
    }
}
