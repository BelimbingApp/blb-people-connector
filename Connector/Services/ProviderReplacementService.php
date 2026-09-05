<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderReplacementReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderReplacementException;
use Illuminate\Support\Facades\DB;

/**
 * Move a workforce's provider identities from a retired connection to the one
 * that replaced it, keeping every workforce entity id exactly as it was.
 *
 * The entity id is the whole point. People-owned records reference it, and a
 * provider replacement is a fact about where the connector reads from, not
 * about who these people are. Nothing outside the connector should be able to
 * tell that the provider changed.
 *
 * The source connection is expected to be inactive: activating the replacement
 * switches the previous connection off, because a scope has one active
 * connection at a time. A replacement is a handover, not two providers running
 * side by side, so this never asks the old connection to still be live.
 */
final class ProviderReplacementService
{
    public function __construct(
        private readonly TenantConnectionLocator $connections,
        private readonly WorkforceIdentityStore $identities,
    ) {}

    /**
     * Apply an operator-approved mapping, all of it or none of it.
     *
     * @param  list<ProviderIdentityMapping>  $mappings
     */
    public function remap(
        int $fromConnectionId,
        int $toConnectionId,
        array $mappings,
        string $reviewReference,
        ?\DateTimeInterface $occurredAt = null,
    ): ProviderReplacementReport {
        $reviewReference = trim($reviewReference);

        if ($reviewReference === '') {
            throw new ProviderReplacementException(
                'A provider replacement rewrites identities a human approved; it requires a review reference.',
            );
        }

        if ($mappings === []) {
            throw new ProviderReplacementException('A provider replacement needs at least one identity mapping.');
        }

        if ($fromConnectionId === $toConnectionId) {
            throw new ProviderReplacementException('A provider replacement needs two different connections.');
        }

        // Both connections are resolved through the tenant-scoped locator, so a
        // connection in another tenant is not found rather than replaced.
        $this->connections->get($fromConnectionId);
        $this->connections->get($toConnectionId);

        $this->assertUnambiguous($mappings);

        $provenance = new WorkforceProvenance('provider.replacement', $reviewReference);
        $at = $occurredAt ?? now();

        // One transaction for the whole mapping. A half-applied replacement
        // would leave an operator with part of a migration and no way to tell
        // which part, which is worse than not having started.
        $remapped = DB::transaction(function () use ($fromConnectionId, $toConnectionId, $mappings, $provenance, $at): int {
            foreach ($mappings as $mapping) {
                $this->identities->remapToConnection(
                    $fromConnectionId,
                    $toConnectionId,
                    $mapping->from,
                    $mapping->to,
                    $at,
                    $provenance,
                );
            }

            return count($mappings);
        });

        return new ProviderReplacementReport($fromConnectionId, $toConnectionId, $remapped, $reviewReference);
    }

    /**
     * Refuse a mapping that does not describe one clean handover per identity.
     *
     * Both directions matter. One source going to two references leaves no
     * answer to "which one is this person now"; two sources arriving at one
     * reference would silently fold two people together, which is a merge and
     * needs its own review, not this one.
     *
     * @param  list<ProviderIdentityMapping>  $mappings
     */
    private function assertUnambiguous(array $mappings): void
    {
        $sources = [];
        $targets = [];

        foreach ($mappings as $mapping) {
            $source = $mapping->from->providerId.'|'.$mapping->from->resourceType->value.'|'.$mapping->from->externalId;
            $target = $mapping->to->providerId.'|'.$mapping->to->resourceType->value.'|'.$mapping->to->externalId;

            if (isset($sources[$source])) {
                throw new ProviderReplacementException(
                    "The mapping sends [{$mapping->from->externalId}] to more than one replacement reference.",
                );
            }

            if (isset($targets[$target])) {
                throw new ProviderReplacementException(
                    "The mapping sends more than one identity to [{$mapping->to->externalId}]; folding two records together is a merge, not a replacement.",
                );
            }

            $sources[$source] = true;
            $targets[$target] = true;
        }
    }
}
