<?php

namespace App\Domains\PeopleConnector\Connector\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Domains\PeopleConnector\Connector\Data\CapabilityDeclaration;
use App\Domains\PeopleConnector\Connector\Data\CapabilityVerification;
use App\Domains\PeopleConnector\Connector\Enums\OperatorAuditOperation;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Exceptions\ConnectorRecordNotFoundException;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Exceptions\ProviderAuthorizationException;
use App\Domains\PeopleConnector\Connector\Models\ProviderConnection;

/**
 * Records deployment evidence for one provider capability into the
 * capability register (#231), the file connector:health:check reads.
 *
 * The register is deployment-wide, but the operator acts in a tenant: the
 * provider must be configured there (a connection exists), the operator
 * must hold connection.manage inside that tenant, and the decision leaves
 * an audit row with the evidence reference. Unknown capability names are
 * refused before anything is written; an entry already present is left as
 * it is (removing evidence is a PR edit, not a command).
 */
final class CapabilityVerifier
{
    public const VERIFY_CAPABILITY = 'people-connector.connection.manage';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly AuthorizationService $authorization,
        private readonly ProviderRegistry $registry,
        private readonly OperatorAuditLog $audit,
    ) {}

    public function verify(Actor $actor, string $providerId, string $capabilityName, string $evidence, ?CapabilityEvidenceRegister $register = null): CapabilityVerification
    {
        $tenantId = $this->tenants->requireTenantId();
        $this->authorization->authorize($actor, self::VERIFY_CAPABILITY);
        if ($actor->validate() !== null || $actor->tenantId !== $tenantId) {
            throw new ProviderAuthorizationException('connector', 'verify_capability', 'Recording capability evidence requires an operator inside the current tenant.');
        }

        // An unknown name is refused here, before the file is touched: the
        // register loader refuses a file naming one, so writing it would
        // break every later health check.
        $capability = PeopleCapability::tryFrom($capabilityName)
            ?? throw new InvalidProviderConfigurationException("Unknown capability [{$capabilityName}]; use one of ".implode(', ', array_map(static fn (PeopleCapability $c): string => $c->value, PeopleCapability::cases())).'.');

        $evidence = trim($evidence);
        if ($evidence === '') {
            throw new InvalidProviderConfigurationException('Evidence is required: pass --evidence=<url-or-ref>.');
        }

        $connection = ProviderConnection::query()->forTenant($tenantId)->where('provider_id', $providerId)->orderBy('id')->first()
            ?? throw new ConnectorRecordNotFoundException("Provider [{$providerId}] is not configured in the current tenant.");

        $register ??= CapabilityEvidenceRegister::fromConfig();
        $provider = $this->registry->find($providerId);
        $declared = $provider !== null && in_array($capability->value, array_map(
            static fn (CapabilityDeclaration $d): string => $d->capability->value,
            $provider->capabilities()->all(),
        ), true);

        if (($existing = $register->entry($providerId, $capability)) !== null) {
            return new CapabilityVerification($providerId, $capability->value, false, $declared,
                "[{$capability->value}] is already verified for [{$providerId}]".($existing['evidence'] === null ? '' : " (evidence: {$existing['evidence']})").'; nothing written.');
        }

        $register->append($providerId, $capability, $evidence, "{$actor->type->value}:{$actor->id}", \DateTimeImmutable::createFromInterface(now()));

        $this->audit->record(
            $actor,
            OperatorAuditOperation::CapabilityVerified,
            (int) $connection->id,
            null,
            $evidence,
            ['provider' => $providerId, 'capability' => $capability->value, 'verified' => false],
            ['provider' => $providerId, 'capability' => $capability->value, 'verified' => true, 'declared_by_adapter' => $declared, 'register' => basename($register->path())],
        );

        return new CapabilityVerification($providerId, $capability->value, true, $declared,
            "Recorded evidence for [{$capability->value}] on [{$providerId}] in {$register->path()}.");
    }
}
