<?php

namespace App\Domains\PeopleConnector\Connector\Data;

use App\Domains\PeopleConnector\Connector\Enums\Hr2000CompanyAxis;
use App\Domains\PeopleConnector\Connector\Enums\Hr2000HostingMode;
use App\Domains\PeopleConnector\Connector\Enums\Hr2000Transport;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;

final readonly class Hr2000DeploymentProfile
{
    /**
     * @param  list<string>  $enabledModules
     */
    public function __construct(
        public ?string $product,
        public ?string $version,
        public Hr2000HostingMode $hostingMode,
        public array $enabledModules,
        public Hr2000Transport $transport,
        public Hr2000CompanyAxis $companyAxis,
        public ?string $vendorSupportReference,
        public ?string $fieldMappingReference,
        public ?string $securityApprovalReference,
        public ?string $timeZone,
        public ?string $encoding,
    ) {
        foreach ($enabledModules as $module) {
            if (! is_string($module) || trim($module) === '') {
                throw new InvalidProviderConfigurationException(
                    'HR2000 enabled modules must be non-empty names copied from approved customer/vendor evidence.',
                );
            }
        }
    }

    public static function undiscovered(): self
    {
        return new self(
            product: null,
            version: null,
            hostingMode: Hr2000HostingMode::Unverified,
            enabledModules: [],
            transport: Hr2000Transport::Unverified,
            companyAxis: Hr2000CompanyAxis::Unverified,
            vendorSupportReference: null,
            fieldMappingReference: null,
            securityApprovalReference: null,
            timeZone: null,
            encoding: null,
        );
    }

    /** @return list<string> */
    public function activationBlockers(): array
    {
        $blockers = [];

        $this->requireText($blockers, 'product_unverified', $this->product);
        $this->requireText($blockers, 'version_unverified', $this->version);
        $this->requireText($blockers, 'vendor_support_unverified', $this->vendorSupportReference);
        $this->requireText($blockers, 'field_mapping_unverified', $this->fieldMappingReference);
        $this->requireText($blockers, 'data_processing_unapproved', $this->securityApprovalReference);
        $this->requireText($blockers, 'timezone_unverified', $this->timeZone);
        $this->requireText($blockers, 'encoding_unverified', $this->encoding);

        if ($this->hostingMode === Hr2000HostingMode::Unverified) {
            $blockers[] = 'hosting_mode_unverified';
        }

        if ($this->enabledModules === []) {
            $blockers[] = 'enabled_modules_unverified';
        }

        if ($this->transport === Hr2000Transport::Unverified) {
            $blockers[] = 'transport_unverified';
        }

        if ($this->companyAxis === Hr2000CompanyAxis::Unverified) {
            $blockers[] = 'company_axis_unverified';
        } elseif ($this->companyAxis === Hr2000CompanyAxis::CoarserThanPlatform) {
            $blockers[] = 'company_axis_ambiguous';
        }

        // No SBG transport or field contract has been supplied. A future
        // provider-specific port removes this blocker only with that evidence.
        if ($this->transport !== Hr2000Transport::Unverified) {
            $blockers[] = 'transport_implementation_unavailable';
        }

        return $blockers;
    }

    public function assertActivatable(): void
    {
        $blockers = $this->activationBlockers();

        if ($blockers !== []) {
            throw new InvalidProviderConfigurationException(
                'HR2000 activation is blocked: '.implode(', ', $blockers).'.',
            );
        }
    }

    /** @param list<string> $blockers */
    private function requireText(array &$blockers, string $blocker, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            $blockers[] = $blocker;
        }
    }
}
