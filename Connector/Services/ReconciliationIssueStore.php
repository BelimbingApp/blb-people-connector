<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\ReconciliationIssueDetails;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Exceptions\ReconciliationIssueConflictException;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Models\WorkforceEntity;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReconciliationIssueStore
{
    public function __construct(
        private TenantContext $tenantContext,
        private TenantConnectionLocator $connections,
    ) {}

    public function report(
        int $connectionId,
        string $issueKey,
        string $kind,
        ?ReconciliationIssueDetails $details = null,
        ?string $resourceType = null,
        ?string $externalId = null,
        ?int $workforceEntityId = null,
        string $severity = 'warning',
        ?\DateTimeInterface $seenAt = null,
    ): ReconciliationIssue {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->assertIdentifiers($issueKey, $kind, $resourceType, $severity, $externalId);
        $this->connections->get($connectionId);

        if ($workforceEntityId !== null) {
            $entity = WorkforceEntity::query()
                ->forTenant($tenantId)
                ->whereKey($workforceEntityId)
                ->first();

            if ($entity === null) {
                throw new ConnectorRecordNotFoundException('The reconciliation workforce entity was not found in the current tenant.');
            }

            if ($resourceType !== null && $entity->resource_type !== $resourceType) {
                throw new InvalidReconciliationIssueException(
                    'The reconciliation resource type does not match its canonical workforce entity.',
                );
            }
        }

        return DB::transaction(function () use ($connectionId, $issueKey, $kind, $details, $resourceType, $externalId, $workforceEntityId, $severity, $seenAt, $tenantId): ReconciliationIssue {
            $observedAt = $seenAt ?? now();
            $detailValues = $details?->toArray();
            $issue = ReconciliationIssue::query()
                ->forTenant($tenantId)
                ->where('connection_id', $connectionId)
                ->where('issue_key', $issueKey)
                ->lockForUpdate()
                ->first();

            if ($issue === null) {
                return ReconciliationIssue::query()->create([
                    'tenant_id' => $tenantId,
                    'connection_id' => $connectionId,
                    'workforce_entity_id' => $workforceEntityId,
                    'issue_key' => $issueKey,
                    'kind' => $kind,
                    'resource_type' => $resourceType,
                    'external_id' => $externalId,
                    'severity' => $severity,
                    'status' => ReconciliationIssue::STATUS_OPEN,
                    'details' => $detailValues,
                    'first_seen_at' => $observedAt,
                    'last_seen_at' => $observedAt,
                ]);
            }

            if ($issue->last_seen_at->greaterThan($observedAt)) {
                return $issue;
            }

            if ($issue->last_seen_at->equalTo($observedAt)) {
                $sameObservation = $issue->kind === $kind
                    && $issue->resource_type === $resourceType
                    && $issue->external_id === $externalId
                    && (int) ($issue->workforce_entity_id ?? 0) === (int) ($workforceEntityId ?? 0)
                    && $issue->severity === $severity
                    && $issue->details === $detailValues;

                if (! $sameObservation) {
                    throw new ReconciliationIssueConflictException(
                        'Conflicting reconciliation evidence shares the same observation time.',
                    );
                }

                return $issue;
            }

            if ($issue->status === ReconciliationIssue::STATUS_RESOLVED) {
                if ($issue->resolved_at !== null && $issue->resolved_at->greaterThanOrEqualTo($observedAt)) {
                    return $issue;
                }

                throw new ReconciliationIssueConflictException(
                    'A resolved reconciliation issue requires an explicit reopen before new evidence is recorded.',
                );
            }

            $issue->fill([
                'workforce_entity_id' => $workforceEntityId,
                'kind' => $kind,
                'resource_type' => $resourceType,
                'external_id' => $externalId,
                'severity' => $severity,
                'status' => ReconciliationIssue::STATUS_OPEN,
                'details' => $detailValues,
                'last_seen_at' => $observedAt,
                'resolved_at' => null,
            ])->save();

            return $issue->refresh();
        });
    }

    public function resolve(int $issueId, ?\DateTimeInterface $resolvedAt = null): ReconciliationIssue
    {
        return DB::transaction(function () use ($issueId, $resolvedAt): ReconciliationIssue {
            $issue = ReconciliationIssue::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->whereKey($issueId)
                ->lockForUpdate()
                ->first()
                ?? throw new ConnectorRecordNotFoundException('The reconciliation issue was not found in the current tenant.');

            if ($issue->status === ReconciliationIssue::STATUS_RESOLVED) {
                return $issue;
            }

            $finishedAt = $resolvedAt ?? now();

            if ($issue->last_seen_at->greaterThan($finishedAt)) {
                throw new ReconciliationIssueConflictException(
                    'A reconciliation issue cannot resolve before its latest observation.',
                );
            }

            $issue->fill([
                'status' => ReconciliationIssue::STATUS_RESOLVED,
                'resolved_at' => $finishedAt,
            ])->save();

            return $issue->refresh();
        });
    }

    public function reopen(
        int $issueId,
        \DateTimeInterface $observedAt,
        ReconciliationIssueDetails $details,
    ): ReconciliationIssue {
        return DB::transaction(function () use ($issueId, $observedAt, $details): ReconciliationIssue {
            $issue = ReconciliationIssue::query()
                ->forTenant($this->tenantContext->requireTenantId())
                ->whereKey($issueId)
                ->lockForUpdate()
                ->first()
                ?? throw new ConnectorRecordNotFoundException('The reconciliation issue was not found in the current tenant.');

            if ($issue->status === ReconciliationIssue::STATUS_OPEN) {
                return $issue;
            }

            if ($issue->last_seen_at->greaterThanOrEqualTo($observedAt)
                || ($issue->resolved_at !== null && $issue->resolved_at->greaterThanOrEqualTo($observedAt))) {
                throw new ReconciliationIssueConflictException(
                    'A reconciliation issue can reopen only for evidence observed after its prior lifecycle.',
                );
            }

            $issue->fill([
                'status' => ReconciliationIssue::STATUS_OPEN,
                'details' => $details->toArray(),
                'last_seen_at' => $observedAt,
                'resolved_at' => null,
            ])->save();

            return $issue->refresh();
        });
    }

    /** @return Collection<int, ReconciliationIssue> */
    public function openForConnection(int $connectionId): Collection
    {
        $tenantId = $this->tenantContext->requireTenantId();
        $this->connections->get($connectionId);

        return ReconciliationIssue::query()
            ->forTenant($tenantId)
            ->where('connection_id', $connectionId)
            ->where('status', ReconciliationIssue::STATUS_OPEN)
            ->orderByDesc('severity')
            ->orderBy('issue_key')
            ->get();
    }

    private function assertIdentifiers(
        string $issueKey,
        string $kind,
        ?string $resourceType,
        string $severity,
        ?string $externalId,
    ): void {
        if ($issueKey === ''
            || strlen($issueKey) > 191
            || strlen($kind) > 80
            || preg_match('/^[a-z0-9]+(?:_[a-z0-9]+)*$/', $kind) !== 1) {
            throw new InvalidReconciliationIssueException('Reconciliation issue identifiers are invalid.');
        }

        if ($resourceType !== null && WorkforceResourceType::tryFrom($resourceType) === null) {
            throw new InvalidReconciliationIssueException('Reconciliation workforce resource type is invalid.');
        }

        if (! in_array($severity, ['info', 'warning', 'error'], true)) {
            throw new InvalidReconciliationIssueException('Reconciliation issue severity is invalid.');
        }

        if ($externalId !== null && strlen($externalId) > 512) {
            throw new InvalidReconciliationIssueException('Reconciliation external identifiers cannot exceed 512 bytes.');
        }
    }
}
