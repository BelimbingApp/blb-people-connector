<?php

namespace App\Domains\PeopleConnector\Connector\Livewire\Reconciliation;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Core\User\Models\User;
use App\Domains\PeopleConnector\Connector\Enums\CommandResolution;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidReconciliationIssueException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;
use App\Domains\PeopleConnector\Connector\Models\ReconciliationIssue;
use App\Domains\PeopleConnector\Connector\Services\CompanyAttribution;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationIssueStore;
use App\Domains\PeopleConnector\Connector\Services\ReconciliationReviewService;
use App\Domains\PeopleConnector\Connector\Services\TenantConnectionLocator;
use App\Domains\PeopleConnector\Connector\Services\WorkforceFreshnessPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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

    /** @var array<int, string> */
    public array $commandResolutions = [];

    public function mount(int $connectionId): void
    {
        $this->connectionId = $connectionId;
        $this->authorizeConnection();
    }

    public function resolveIssue(int $issueId, ReconciliationIssueStore $issues): void
    {
        $this->authorizeConnection();
        $note = $this->validatedNote($issueId);
        $this->openIssue($issueId, $issues);
        $issue = $issues->resolve($issueId);

        $this->record('people_connector.reconciliation.resolved', __('Resolved reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'note' => $note,
        ], __('Resolve issue'));

        unset($this->resolutionNotes[$issueId]);
        $this->notify(__('Reconciliation issue resolved.'));
    }

    public function applyMerge(int $issueId, ReconciliationReviewService $reviews): void
    {
        $this->authorizeConnection();
        $reviewReference = $this->validatedReviewReference($issueId);
        $occurredAt = now();

        // The service returns the issue it locked and resolved. Build the audit
        // evidence from that decision, never from an earlier unlocked read that
        // a concurrent sync observation could have superseded.
        try {
            $issue = $reviews->applyMerge(
                $this->connectionId,
                $issueId,
                $reviewReference,
                $occurredAt,
            );
        } catch (ConnectorRecordNotFoundException) {
            abort(404);
        } catch (InvalidReconciliationIssueException $exception) {
            throw ValidationException::withMessages([
                "reviewReferences.{$issueId}" => $exception->getMessage(),
            ]);
        }
        $survivorExternalId = (string) ($issue->details['related_external_id'] ?? '');

        $this->record('people_connector.reconciliation.merge_applied', __('Applied the reviewed merge for reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'review_reference' => $reviewReference,
            'surviving_external_id' => $survivorExternalId,
        ], __('Apply reviewed merge'));

        unset($this->reviewReferences[$issueId]);
        $this->notify(__('Reviewed merge applied and issue resolved.'));
    }

    public function remapIdentity(int $issueId, ReconciliationIssueStore $issues, ReconciliationReviewService $reviews): void
    {
        $this->authorizeConnection();
        $issue = $this->openIssue($issueId, $issues);
        $this->validateRemapIssue($issue, $issueId);
        $reviewReference = $this->validatedReviewReference($issueId);
        $replacementExternalId = trim((string) $this->replacementExternalIds[$issueId]);
        $occurredAt = now();

        try {
            $issue = $reviews->applyRemap(
                $this->connectionId,
                $issueId,
                $replacementExternalId,
                $reviewReference,
                $occurredAt,
            );
        } catch (ConnectorRecordNotFoundException) {
            abort(404);
        } catch (InvalidReconciliationIssueException $exception) {
            throw ValidationException::withMessages([
                "replacementExternalIds.{$issueId}" => $exception->getMessage(),
            ]);
        }

        $this->record('people_connector.reconciliation.identity_remapped', __('Remapped the reviewed identity for reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'review_reference' => $reviewReference,
            'replacement_external_id' => $replacementExternalId,
        ], __('Apply reviewed remap'));

        unset($this->reviewReferences[$issueId], $this->replacementExternalIds[$issueId]);
        $this->notify(__('Reviewed identity remap applied and issue resolved.'));
    }

    public function confirmUnknownOutcome(int $issueId, ReconciliationReviewService $reviews): void
    {
        $this->authorizeConnection();
        $resolution = $this->validatedCommandResolution($issueId);
        $reviewReference = $this->validatedReviewReference($issueId);
        $occurredAt = now();

        // Confirming what the provider did is a bookkeeping decision, not a
        // resend. The service closes the issue and records the operator's
        // conclusion; re-issuing the command stays a separate, deliberate act.
        try {
            $issue = $reviews->confirmCommandOutcome(
                $this->connectionId,
                $issueId,
                $resolution,
                $reviewReference,
                $occurredAt,
            );
        } catch (ConnectorRecordNotFoundException) {
            abort(404);
        } catch (InvalidReconciliationIssueException $exception) {
            throw ValidationException::withMessages([
                "commandResolutions.{$issueId}" => $exception->getMessage(),
            ]);
        }

        $this->record('people_connector.reconciliation.command_outcome_confirmed', __('Confirmed the command outcome for reconciliation issue :key.', ['key' => $issue->issue_key]), $issue, [
            'review_reference' => $reviewReference,
            'resolution' => $resolution->value,
        ], __('Confirm command outcome'));

        unset($this->reviewReferences[$issueId], $this->commandResolutions[$issueId]);
        $this->notify(__('Command outcome confirmed and issue resolved.'));
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
        try {
            return $issues->requireOpenForConnection($this->connectionId, $issueId);
        } catch (ConnectorRecordNotFoundException) {
            abort(404);
        }
    }

    private function connection(): ProviderConnection
    {
        try {
            return app(TenantConnectionLocator::class)->get($this->connectionId);
        } catch (ConnectorRecordNotFoundException) {
            abort(404);
        }
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
        $this->validate([
            "resolutionNotes.{$issueId}" => ['required', 'string', 'max:1000'],
        ]);

        return trim((string) $this->resolutionNotes[$issueId]);
    }

    private function validatedReviewReference(int $issueId): string
    {
        $this->validate([
            "reviewReferences.{$issueId}" => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9]+(?:[._:\/-][A-Za-z0-9]+)*$/'],
        ]);

        return trim((string) $this->reviewReferences[$issueId]);
    }

    private function validatedCommandResolution(int $issueId): CommandResolution
    {
        $this->validate([
            "commandResolutions.{$issueId}" => ['required', Rule::enum(CommandResolution::class)],
        ]);

        return CommandResolution::from((string) $this->commandResolutions[$issueId]);
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
