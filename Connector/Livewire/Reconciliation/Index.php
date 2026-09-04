<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Reconciliation;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Data\ExternalReference;
use App\Domains\PeopleConnector\Connector\Data\WorkforceProvenance;
use App\Domains\PeopleConnector\Connector\Enums\WorkforceResourceType;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use App\Domains\PeopleConnector\Connector\Services\WorkforceIdentityStore;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithPagination;

final class Index extends Component
{
    use InteractsWithNotifications;
    use WithPagination;

    public int $connectionId;

    /** @var array<int, string> */
    public array $resolutionNotes = [];

    /** @var array<int, string> */
    public array $reviewReferences = [];

    /** @var array<int, string> */
    public array $replacementExternalIds = [];

    public function mount(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        $this->authorizeConnection();
    }

    public function resolveIssue(int $issueId, ReconciliationIssueStore $issues): void
    {
        $issue = $this->openIssue($issueId, $issues);
        $this->authorizeConnection();
        $note = $this->validatedNote($issueId);
        $issues->resolve($issueId);

        $this->record('people_connector.reconciliation.resolved', __('Resolved reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'note' => $note,
        ], __('Resolve issue'));

        unset($this->resolutionNotes[$issueId]);
        $this->notify(__('Reconciliation issue resolved.'));
    }

    public function applyMerge(int $issueId, ReconciliationIssueStore $issues, WorkforceIdentityStore $identities): void
    {
        $issue = $this->openIssue($issueId, $issues);
        $this->authorizeConnection();
        $this->validateMergeIssue($issue);
        $reviewReference = $this->validatedReviewReference($issueId);
        $connection = $this->connection();
        $resourceType = WorkforceResourceType::from((string) $issue->resource_type);
        $survivorExternalId = (string) ($issue->details['related_external_id'] ?? '');
        $occurredAt = now();

        $identities->merge(
            $this->connectionId,
            new ExternalReference($connection->provider_id, $resourceType, (string) $issue->external_id),
            new ExternalReference($connection->provider_id, $resourceType, $survivorExternalId),
            $occurredAt,
            new WorkforceProvenance('reconciliation.review', $reviewReference),
        );
        $issues->resolve($issueId, $occurredAt);

        $this->record('people_connector.reconciliation.merge_applied', __('Applied the reviewed merge for reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'review_reference' => $reviewReference,
            'surviving_external_id' => $survivorExternalId,
        ], __('Apply reviewed merge'));

        unset($this->reviewReferences[$issueId]);
        $this->notify(__('Reviewed merge applied and issue resolved.'));
    }

    public function remapIdentity(int $issueId, ReconciliationIssueStore $issues, WorkforceIdentityStore $identities): void
    {
        $issue = $this->openIssue($issueId, $issues);
        $this->authorizeConnection();
        $this->validateRemapIssue($issue, $issueId);
        $reviewReference = $this->validatedReviewReference($issueId);
        $connection = $this->connection();
        $resourceType = WorkforceResourceType::from((string) $issue->resource_type);
        $replacementExternalId = trim((string) $this->replacementExternalIds[$issueId]);
        $occurredAt = now();

        $identities->remap(
            $this->connectionId,
            new ExternalReference($connection->provider_id, $resourceType, (string) $issue->external_id),
            new ExternalReference($connection->provider_id, $resourceType, $replacementExternalId),
            $occurredAt,
            new WorkforceProvenance('reconciliation.review', $reviewReference),
        );
        $issues->resolve($issueId, $occurredAt);

        $this->record('people_connector.reconciliation.identity_remapped', __('Remapped the reviewed identity for reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'review_reference' => $reviewReference,
            'replacement_external_id' => $replacementExternalId,
        ], __('Apply reviewed remap'));

        unset($this->reviewReferences[$issueId], $this->replacementExternalIds[$issueId]);
        $this->notify(__('Reviewed identity remap applied and issue resolved.'));
    }

    public function render(ReconciliationIssueStore $issues, WorkforceFreshnessPolicy $freshnessPolicy): View
    {
        $connection = $this->connection();

        return view('people-connector::livewire.reconciliation.index', [
            'connection' => $connection,
            'freshness' => $freshnessPolicy->for($this->connectionId),
            'issues' => $issues->paginateOpenForConnection($this->connectionId),
        ]);
    }

    private function openIssue(int $issueId, ReconciliationIssueStore $issues): ReconciliationIssue
    {
        return $issues->openForConnection($this->connectionId)
            ->firstWhere('id', $issueId)
            ?? throw new ConnectorRecordNotFoundException('The reconciliation issue is not open for this connection.');
    }

    private function connection(): ProviderConnection
    {
        return app(TenantConnectionLocator::class)->get($this->connectionId);
    }

    private function authorizeConnection(): void
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        app(AuthorizationService::class)->authorize(
            Actor::forUser($user),
            'people-connector.identity.manage',
        );

        abort_unless(app(CompanyAttribution::class)->mayActForConnection($user, $this->connection()), 403);
    }

    private function validatedNote(int $issueId): string
    {
        return $this->validate([
            "resolutionNotes.{$issueId}" => ['required', 'string', 'max:1000'],
        ])["resolutionNotes.{$issueId}"];
    }

    private function validatedReviewReference(int $issueId): string
    {
        return $this->validate([
            "reviewReferences.{$issueId}" => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9]+(?:[._:\/-][A-Za-z0-9]+)*$/'],
        ])["reviewReferences.{$issueId}"];
    }

    private function validateMergeIssue(ReconciliationIssue $issue): void
    {
        if ($issue->kind !== 'sync_merge_requested'
            || $issue->resource_type === null
            || $issue->external_id === null
            || ! is_string($issue->details['related_external_id'] ?? null)
            || trim($issue->details['related_external_id']) === '') {
            abort(422, 'This reconciliation issue does not contain a complete queued merge.');
        }
    }

    private function validateRemapIssue(ReconciliationIssue $issue, int $issueId): void
    {
        if ($issue->resource_type === null || $issue->external_id === null) {
            abort(422, 'This reconciliation issue does not identify an external workforce record.');
        }

        $replacementExternalId = trim((string) ($this->replacementExternalIds[$issueId] ?? ''));
        $this->validate([
            "replacementExternalIds.{$issueId}" => ['required', 'string', 'max:512'],
        ]);

        if ($replacementExternalId === $issue->external_id) {
            throw ValidationException::withMessages([
                "replacementExternalIds.{$issueId}" => __('The replacement external ID must differ from the current ID.'),
            ]);
        }
    }

    /** @param array<string, string> $context */
    private function record(string $event, string $summary, ReconciliationIssue $issue, array $context, string $uiElement): void
    {
        app(SemanticActionRecorder::class)->record(
            event: $event,
            summary: $summary,
            source: __('People connections'),
            subject: ['name' => 'reconciliation_issue', 'id' => $issue->id, 'identifier' => $issue->issue_key],
            surface: 'admin.people-connector.reconciliation.index',
            uiElement: $uiElement,
            context: $context,
        );
    }
}
