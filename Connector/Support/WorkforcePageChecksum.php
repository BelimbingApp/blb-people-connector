<?php

namespace App\Domains\PeopleConnector\Connector\Support;

use App\Domains\PeopleConnector\Connector\Data\WorkforceChangePage;
use App\Domains\PeopleConnector\Connector\Data\WorkforcePage;
use BackedEnum;
use DateTimeInterface;
use DateTimeZone;
use ReflectionClass;
use ReflectionProperty;

/**
 * The canonical fingerprint of a feed page's content (#204, plan 0001: adapter
 * paging must not contradict itself).
 *
 * An adapter that can compute it declares the checksum on the page; the sync
 * runner recomputes it from the typed records it received and refuses the
 * page before projecting anything when the two differ. The fingerprint covers
 * the records only, never the cursors or the as-of instant: a page is the
 * same page however it was reached.
 *
 * Canonical form: every record and change becomes a map of its public
 * properties in declaration order, recursively (references and nested records
 * the same way, instants as UTC RFC 3339, enums as their value), JSON-encoded
 * with slashes and unicode unescaped; the fingerprint is SHA-256 of that JSON.
 */
final class WorkforcePageChecksum
{
    public const ALGORITHM = 'sha256';

    public static function of(WorkforcePage|WorkforceChangePage $page): string
    {
        $content = $page instanceof WorkforcePage
            ? [
                'companies' => array_map(self::canonical(...), $page->companies),
                'organization_units' => array_map(self::canonical(...), $page->organizationUnits),
                'positions' => array_map(self::canonical(...), $page->positions),
                'employees' => array_map(self::canonical(...), $page->employees),
            ]
            : ['changes' => array_map(self::canonical(...), $page->changes)];

        return hash(self::ALGORITHM, (string) json_encode($content, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public static function isWellFormed(string $checksum): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $checksum) === 1;
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new DateTimeZone('UTC'))->format(DATE_RFC3339_EXTENDED);
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        if (is_object($value)) {
            $canonical = ['@type' => $value::class];
            foreach ((new ReflectionClass($value))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
                $canonical[$property->getName()] = self::canonical($property->getValue($value));
            }

            return $canonical;
        }

        return $value;
    }
}
