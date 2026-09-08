<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\ProviderIdentityMapping;
use App\Domains\PeopleConnector\Connector\Data\ProviderReplacementReport;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\ExternalIdentityCollisionException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderReplacementException;
use App\Domains\PeopleConnector\Connector\Models\ExternalIdentity;
use App\Domains\PeopleConnector\Connector\Models\OperatorAudit;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
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
    public const REMAP_CAPABILITY = 'people-connector.identity.manage';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuthorizationService $authorization,
        private readonly OperatorAuditLog $audit,
        private readonly TenantConnectionLocator $connections,
        private readonly WorkforceIdentityStore $identities,
    ) {}

    /**
     * Apply an operator-approved mapping, all of it or none of it.
     *
     * @param  list<ProviderIdentityMapping>  $mappings
     */
    public function remap(
        Actor $actor,
        int $fromConnectionId,
        int $toConnectionId,
        array $mappings,
        string $reviewReference,
        ?\DateTimeInterface $occurredAt = null,
    ): ProviderReplacementReport {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::REMAP_CAPABILITY);

        // Plan 0001: mapping changes require scoped authority and audit. The
        // actor is the scope; the row written below is the audit.
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'replace_provider',
                message: 'A provider replacement requires an actor inside its tenant.',
            );
        }

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
        $from = $this->connections->get($fromConnectionId);
        $to = $this->connections->get($toConnectionId);

        // The tenant is not the boundary that matters here. Activation only
        // retires peers in the same scope, so a sibling company's connection is
        // live alongside this one, and handing these entities to it would
        // reattach a whole workforce to the wrong company while reporting a
        // successful reviewed replacement. A replacement swaps the provider
        // behind one scope; it never moves anyone between scopes.
        if ($from->scope_key !== $to->scope_key) {
            throw new ProviderReplacementException(
                "A provider replacement stays inside one scope; [{$from->scope_key}] cannot hand its identities to [{$to->scope_key}].",
            );
        }

        $this->assertUnambiguous($mappings);

        $provenance = new WorkforceProvenance('provider.replacement', $reviewReference);
        $at = $occurredAt ?? now();

        // One transaction for the whole mapping. A half-applied replacement
        // would leave an operator with part of a migration and no way to tell
        // which part, which is worse than not having started.
        $remapped = DB::transaction(function () use ($actor, $from, $to, $fromConnectionId, $toConnectionId, $mappings, $provenance, $reviewReference, $at): int {
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

            // External ids are identifiers the operator already reviewed, not
            // contents; the audit names them so a remap can be traced later.
            $this->audit->record(
                $actor,
                OperatorAuditOperation::IdentitiesRemapped,
                $fromConnectionId,
                $toConnectionId,
                $reviewReference,
                ['provider_id' => $from->provider_id, 'scope_key' => $from->scope_key, 'external_ids' => array_map(fn (ProviderIdentityMapping $mapping): string => $mapping->from->externalId, $mappings)],
                ['provider_id' => $to->provider_id, 'remapped' => count($mappings), 'external_ids' => array_map(fn (ProviderIdentityMapping $mapping): string => $mapping->to->externalId, $mappings)],
                $at,
            );

            return count($mappings);
        });

        return new ProviderReplacementReport($fromConnectionId, $toConnectionId, $remapped, $reviewReference);
    }

    /**
     * Reverse a recorded replacement, all of it or none of it.
     *
     * Reversible while nothing has observed the new identities: every identity
     * the audit handed over must still be active on the replacement connection
     * with its last observation at the handover, and the source connection must
     * not be retired. Past either boundary the handover is history and the
     * whole rollback is refused, naming the first identity past it.
     *
     * The report reads in the direction the identities move: from the
     * replacement connection back to the source.
     */
    public function rollback(
        Actor $actor,
        int $auditId,
        string $reviewReference,
        ?\DateTimeInterface $occurredAt = null,
    ): ProviderReplacementReport {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->authorization->authorize($actor, self::REMAP_CAPABILITY);

        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException(
                providerId: 'connector',
                operation: 'replace_provider',
                message: 'A provider replacement rollback requires an actor inside its tenant.',
            );
        }

        $reviewReference = trim($reviewReference);

        if ($reviewReference === '') {
            throw new ProviderReplacementException(
                'A provider replacement rollback reverses a reviewed decision; it requires a review reference.',
            );
        }

        // The audit is the record of what was handed over. Another tenant's
        // audit is not found rather than rolled back.
        $audit = OperatorAudit::query()
            ->forTenant($tenantId)
            ->whereKey($auditId)
            ->where('operation', OperatorAuditOperation::IdentitiesRemapped->value)
            ->first()
            ?? throw new ConnectorRecordNotFoundException('The provider replacement audit was not found in the current tenant.');

        if ($audit->connection_id === null || $audit->related_connection_id === null) {
            throw new ProviderReplacementException("Audit [{$auditId}] does not name both connections of a replacement.");
        }

        $rolledBack = OperatorAudit::query()
            ->forTenant($tenantId)
            ->where('operation', OperatorAuditOperation::IdentitiesRemapRolledBack->value)
            ->where('before_summary->audit_id', $auditId)
            ->exists();

        if ($rolledBack) {
            throw new ProviderReplacementException("Audit [{$auditId}] has already been rolled back.");
        }

        $fromConnectionId = (int) $audit->connection_id;
        $toConnectionId = (int) $audit->related_connection_id;
        $from = $this->connections->get($fromConnectionId);
        $to = $this->connections->get($toConnectionId);

        // The same scope rule as remap(), read backwards: a rollback hands
        // identities to the source, and that must not cross a company scope
        // any more than the replacement could.
        if ($from->scope_key !== $to->scope_key) {
            throw new ProviderReplacementException(
                "A provider replacement stays inside one scope; [{$to->scope_key}] cannot hand its identities back to [{$from->scope_key}].",
            );
        }

        if ($from->status === ProviderConnection::STATUS_RETIRED) {
            throw new ProviderReplacementException(
                "Connection [{$fromConnectionId}] is retired; its handovers are history and retirement is the irreversible step.",
            );
        }

        $pairs = $this->handoversOnRecord($tenantId, $audit, $fromConnectionId, $toConnectionId);
        $provenance = new WorkforceProvenance('provider.replacement_rollback', $reviewReference);
        $at = $occurredAt ?? now();

        $reversed = DB::transaction(function () use ($actor, $audit, $from, $to, $fromConnectionId, $toConnectionId, $pairs, $provenance, $reviewReference, $at): int {
            foreach ($pairs as [$oldReference, $newReference]) {
                try {
                    $this->identities->reverseHandover(
                        $fromConnectionId,
                        $toConnectionId,
                        $oldReference,
                        $newReference,
                        $at,
                        $provenance,
                    );
                } catch (ExternalIdentityCollisionException $pastBoundary) {
                    // The store names the identity; the caller asked for a
                    // replacement operation and gets a replacement refusal.
                    throw new ProviderReplacementException($pastBoundary->getMessage(), previous: $pastBoundary);
                }
            }

            $this->audit->record(
                $actor,
                OperatorAuditOperation::IdentitiesRemapRolledBack,
                $toConnectionId,
                $fromConnectionId,
                $reviewReference,
                ['audit_id' => (int) $audit->id, 'provider_id' => $to->provider_id, 'scope_key' => $to->scope_key, 'external_ids' => array_map(static fn (array $pair): string => $pair[1]->externalId, $pairs)],
                ['provider_id' => $from->provider_id, 'rolled_back' => count($pairs), 'external_ids' => array_map(static fn (array $pair): string => $pair[0]->externalId, $pairs)],
                $at,
            );

            return count($pairs);
        });

        return new ProviderReplacementReport($toConnectionId, $fromConnectionId, $reversed, $reviewReference);
    }

    /**
     * Pair the audit's external ids with the identity rows that record them.
     *
     * The audit names external ids, not rows. The row that proves a handover
     * is the source identity marked `remapped` whose replacement sits on the
     * replacement connection under the paired external id; that link also
     * supplies the resource type the audit does not carry.
     *
     * @return list<array{0: ExternalReference, 1: ExternalReference}>
     */
    private function handoversOnRecord(int $tenantId, OperatorAudit $audit, int $fromConnectionId, int $toConnectionId): array
    {
        $oldIds = array_values((array) ($audit->before_summary['external_ids'] ?? []));
        $newIds = array_values((array) ($audit->after_summary['external_ids'] ?? []));

        if ($oldIds === [] || count($oldIds) !== count($newIds)) {
            throw new ProviderReplacementException("Audit [{$audit->id}] does not pair its source and replacement identities.");
        }

        $pairs = [];

        foreach ($oldIds as $index => $oldId) {
            $newId = (string) $newIds[$index];
            $old = ExternalIdentity::query()
                ->forTenant($tenantId)
                ->where('connection_id', $fromConnectionId)
                ->where('external_id_hash', hash('sha256', (string) $oldId))
                ->where('external_id', (string) $oldId)
                ->where('state', ExternalIdentity::STATE_REMAPPED)
                ->whereNotNull('replaced_by_identity_id')
                ->get()
                ->first(fn (ExternalIdentity $candidate): bool => ExternalIdentity::query()
                    ->forTenant($tenantId)
                    ->whereKey($candidate->replaced_by_identity_id)
                    ->where('connection_id', $toConnectionId)
                    ->where('external_id', $newId)
                    ->exists())
                ?? throw new ProviderReplacementException("No handover of [{$oldId}] to [{$newId}] is on record; the rollback cannot reverse it.");

            $type = WorkforceResourceType::from($old->resource_type);
            $pairs[] = [
                new ExternalReference($old->provider_id, $type, (string) $oldId),
                new ExternalReference((string) $audit->after_summary['provider_id'], $type, $newId),
            ];
        }

        return $pairs;
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
