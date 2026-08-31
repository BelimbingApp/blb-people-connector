<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\WorkforceHistoryEventType;

final readonly class WorkforceHistoryEvent
{
    /**
     * @param  array<string, bool|int|string|array<string, string>|null>  $payload
     */
    private function __construct(
        public WorkforceHistoryEventType $type,
        public ExternalReference $subjectReference,
        public ?int $subjectEntityId,
        public ?ExternalReference $relatedReference,
        public ?int $relatedIdentityId,
        public ?int $relatedEntityId,
        private array $payload,
    ) {}

    public static function identityAttached(ExternalReference $reference): self
    {
        return new self(
            WorkforceHistoryEventType::IdentityAttached,
            $reference,
            null,
            null,
            null,
            null,
            ['external_id' => $reference->externalId],
        );
    }

    public static function identityRemapped(
        ExternalReference $supersededReference,
        ExternalReference $replacementReference,
        int $replacementIdentityId,
        int $replacementEntityId,
    ): self {
        return new self(
            WorkforceHistoryEventType::IdentityRemapped,
            $supersededReference,
            null,
            $replacementReference,
            $replacementIdentityId,
            $replacementEntityId,
            [
                'superseded_external_id' => $supersededReference->externalId,
                'replacement_external_id' => $replacementReference->externalId,
                'replacement_identity_id' => $replacementIdentityId,
            ],
        );
    }

    public static function entityMerged(
        ExternalReference $supersededReference,
        ExternalReference $survivingReference,
        int $survivingIdentityId,
        int $supersededEntityId,
        int $survivingEntityId,
    ): self {
        return new self(
            WorkforceHistoryEventType::EntityMerged,
            $supersededReference,
            $supersededEntityId,
            $survivingReference,
            $survivingIdentityId,
            $survivingEntityId,
            [
                'superseded_entity_id' => $supersededEntityId,
                'surviving_entity_id' => $survivingEntityId,
                'surviving_external_id' => $survivingReference->externalId,
            ],
        );
    }

    public static function identityDeactivated(ExternalReference $reference): self
    {
        return new self(
            WorkforceHistoryEventType::IdentityDeactivated,
            $reference,
            null,
            null,
            null,
            null,
            ['external_id' => $reference->externalId],
        );
    }

    public static function projectionUpserted(
        WorkforceCompany|WorkforceOrganizationUnit|WorkforcePosition|WorkforceEmployee $record,
    ): self {
        $base = [
            'reference' => self::referencePayload($record->reference),
            'active' => $record->active,
            'observed_at' => $record->observedAt->format(DATE_ATOM),
            'source_version' => $record->sourceVersion,
        ];

        $payload = match (true) {
            $record instanceof WorkforceCompany => $base + [
                'name' => $record->name,
                'code' => $record->code,
            ],
            $record instanceof WorkforceOrganizationUnit => $base + [
                'company_reference' => self::referencePayload($record->companyReference),
                'parent_reference' => self::referencePayload($record->parentReference),
                'name' => $record->name,
                'code' => $record->code,
                'kind' => $record->kind,
                'effective_at' => $record->effectiveAt->format(DATE_ATOM),
            ],
            $record instanceof WorkforcePosition => $base + [
                'company_reference' => self::referencePayload($record->companyReference),
                'organization_reference' => self::referencePayload($record->organizationReference),
                'name' => $record->name,
                'code' => $record->code,
                'tier' => $record->tier,
                'effective_at' => $record->effectiveAt->format(DATE_ATOM),
            ],
            default => $base + [
                'company_reference' => self::referencePayload($record->companyReference),
                'user_reference' => self::referencePayload($record->userReference),
                'organization_reference' => self::referencePayload($record->organizationReference),
                'position_reference' => self::referencePayload($record->positionReference),
                'manager_reference' => self::referencePayload($record->managerReference),
                'department_head_reference' => self::referencePayload($record->departmentHeadReference),
                'display_name' => $record->displayName,
                'employee_number' => $record->employeeNumber,
                'email' => $record->email,
                'effective_at' => $record->effectiveAt->format(DATE_ATOM),
            ],
        };

        return new self(
            WorkforceHistoryEventType::ProjectionUpserted,
            $record->reference,
            null,
            null,
            null,
            null,
            $payload,
        );
    }

    /** @return array<string, bool|int|string|array<string, string>|null> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, string>|null */
    private static function referencePayload(?ExternalReference $reference): ?array
    {
        if ($reference === null) {
            return null;
        }

        return [
            'provider_id' => $reference->providerId,
            'resource_type' => $reference->resourceType->value,
            'external_id' => $reference->externalId,
        ];
    }
}
