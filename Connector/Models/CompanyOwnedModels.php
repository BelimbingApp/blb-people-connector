<?php

namespace App\Domains\PeopleConnector\Connector\Models;

use App\Domains\PeopleConnector\Connector\Models\Concerns\CompanyOwned;
use Illuminate\Database\Eloquent\Model;

/**
 * Every model in this domain that declares itself company-owned, found by
 * looking rather than by being told.
 *
 * Two things consume this list and both used to keep their own: the
 * isolation contract (so a new slice is enrolled by using the trait) and the
 * company merge (so a new slice's rows follow the survivor). The merge kept
 * its list by hand and it was five tables short by the time anyone looked
 * (blb-people-connector#29). One list, derived, is the fix for that class of
 * omission — not a longer hand-written one.
 *
 * Resolved once per process: the scan is a directory walk plus a regex per
 * file, cheap enough for the admin-frequency operations that need it and
 * far too slow to repeat per query.
 */
final class CompanyOwnedModels
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

            if (is_subclass_of($class, Model::class) && in_array(CompanyOwned::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        sort($models);

        return self::$discovered = array_values(array_unique($models));
    }

    /**
     * The models whose rows name their owning company directly in the given
     * column — the ones a company merge must rewrite. A model that *is* a
     * company (owner column is its own entity id) or that inherits ownership
     * through a parent (null owner column) is excluded: the first is retired
     * by the merge itself, the second follows its parent.
     *
     * @return list<class-string<Model>>
     */
    public static function owningCompanyThrough(string $column): array
    {
        return array_values(array_filter(
            self::all(),
            fn (string $model): bool => (new $model)->companyOwnerColumn() === $column,
        ));
    }

    /**
     * Model files, found by directory rather than by declaration.
     *
     * A company-owned model placed outside a `Models` directory enrols itself
     * into nothing, silently. That is a deliberate trade — parsing every file
     * in the domain to find Eloquent subclasses costs more than it buys while
     * the house layout puts models in `Models` — but it is a real limit, and
     * the companion "the repository actually contains company-owned models"
     * test only catches total discovery failure, not one missed model.
     *
     * @return list<\SplFileInfo>
     * @return list<\SplFileInfo>
     */
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
