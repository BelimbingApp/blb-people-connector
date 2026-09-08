<?php

namespace App\Domains\PeopleConnector\Connector\Data;

/**
 * What an HR2000 import would do to one connection's employee projections
 * (#298), read without writing. Every entry names an EmpNo and, for
 * would_update, the projection fields that differ; never a field value.
 *
 * @phpstan-type Entry array{employee_number: string, fields?: list<string>}
 */
final readonly class Hr2000ImportReconciliation
{
    public const WOULD_CREATE = 'would_create';

    public const WOULD_UPDATE = 'would_update';

    public const WOULD_DEACTIVATE = 'would_deactivate';

    public const WOULD_REACTIVATE = 'would_reactivate';

    public const UNCHANGED = 'unchanged';

    public const MISSING_FROM_FILE = 'missing_from_file';

    public const CLASSES = [self::WOULD_CREATE, self::WOULD_UPDATE, self::WOULD_DEACTIVATE, self::WOULD_REACTIVATE, self::UNCHANGED, self::MISSING_FROM_FILE];

    /** @var array<string, list<Entry>> class => entries, every class present */
    public array $classes;

    /** @param  array<string, list<Entry>>  $classes */
    public function __construct(public int $connectionId, array $classes)
    {
        if (array_diff(array_keys($classes), self::CLASSES) !== []) {
            throw new \InvalidArgumentException('HR2000 import reconciliations use the fixed classification vocabulary.');
        }
        $this->classes = array_combine(self::CLASSES, array_map(static fn (string $class): array => $classes[$class] ?? [], self::CLASSES));
    }

    /** @return array<string, int> class => count, in vocabulary order */
    public function counts(): array
    {
        return array_map('count', $this->classes);
    }

    /** @return array{connection: int, counts: array<string, int>, classes: array<string, list<Entry>>} */
    public function toArray(): array
    {
        return ['connection' => $this->connectionId, 'counts' => $this->counts(), 'classes' => $this->classes];
    }
}
