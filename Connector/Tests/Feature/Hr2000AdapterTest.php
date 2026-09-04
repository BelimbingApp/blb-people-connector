<?php

use App\Domains\PeopleConnector\Connector\Contracts\BootstrapsWorkforce;
use App\Domains\PeopleConnector\Connector\Data\Hr2000DeploymentProfile;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\CapabilityDirection;
use App\Domains\PeopleConnector\Connector\Enums\Hr2000CompanyAxis;
use App\Domains\PeopleConnector\Connector\Enums\Hr2000HostingMode;
use App\Domains\PeopleConnector\Connector\Enums\Hr2000Transport;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;
use App\Domains\PeopleConnector\Connector\Enums\ProviderHealthState;
use App\Domains\PeopleConnector\Connector\Exceptions\InvalidProviderConfigurationException;
use App\Domains\PeopleConnector\Connector\Providers\Hr2000Adapter;
use App\Domains\PeopleConnector\Connector\Services\ProviderRegistry;
use App\Domains\PeopleConnector\Connector\Testing\ProviderConformance;

test('the undiscovered HR2000 adapter is registrable but exposes no provider operation', function (): void {
    $adapter = new Hr2000Adapter(Hr2000DeploymentProfile::undiscovered());
    $registry = new ProviderRegistry;

    $registry->register($adapter);

    expect($registry->find(Hr2000Adapter::ID))->toBe($adapter)
        ->and($adapter->descriptor()->id)->toBe('hr2000.sbg')
        ->and($adapter->capabilities()->all())->toBe([])
        ->and($adapter->capabilities()->direction(PeopleCapability::EmployeeDirectory))->toBe(CapabilityDirection::None)
        ->and($adapter->resolvePort(
            BootstrapsWorkforce::class,
            ProviderPortAuthorization::forConformance(Hr2000Adapter::ID),
        ))->toBeNull()
        ->and($adapter->health()->state)->toBe(ProviderHealthState::Unknown)
        ->and(ProviderConformance::violations($adapter))->toBe([]);
});

test('incomplete SBG discovery fails activation without exposing profile values', function (): void {
    $adapter = new Hr2000Adapter(Hr2000DeploymentProfile::undiscovered());

    expect(fn () => $adapter->assertActivatable())
        ->toThrow(InvalidProviderConfigurationException::class, 'product_unverified')
        ->and($adapter->capabilities()->all())->toBe([]);
});

test('a provider-coarser company axis is rejected even when every other fact is supplied', function (): void {
    $profile = hr2000VerifiedProfile(companyAxis: Hr2000CompanyAxis::CoarserThanPlatform);

    expect($profile->activationBlockers())->toContain('company_axis_ambiguous')
        ->and(fn () => $profile->assertActivatable())
        ->toThrow(InvalidProviderConfigurationException::class, 'company_axis_ambiguous');
});

test('missing data-processing approval independently blocks activation', function (): void {
    $profile = hr2000VerifiedProfile(securityApprovalReference: null);

    expect($profile->activationBlockers())->toContain('data_processing_unapproved')
        ->and(fn () => $profile->assertActivatable())
        ->toThrow(InvalidProviderConfigurationException::class, 'data_processing_unapproved');
});

test('approved profile facts cannot imply a transport implementation that does not exist', function (): void {
    $profile = hr2000VerifiedProfile(transport: Hr2000Transport::FileExchange);

    expect($profile->activationBlockers())->toBe(['transport_implementation_unavailable'])
        ->and(fn () => $profile->assertActivatable())
        ->toThrow(InvalidProviderConfigurationException::class, 'transport_implementation_unavailable');
});

test('unsupported and undocumented transport names fail with a connector configuration exception', function (): void {
    expect(fn () => Hr2000Transport::fromConfiguration('screen_scraping'))
        ->toThrow(InvalidProviderConfigurationException::class, 'not permitted');
});

test('enabled module evidence rejects blank or non-string entries', function (array $modules): void {
    expect(fn () => hr2000VerifiedProfile(enabledModules: $modules))
        ->toThrow(InvalidProviderConfigurationException::class, 'non-empty names');
})->with([
    'blank module' => [['Quick Staff', '  ']],
    'non-string module' => [['Quick Staff', 2]],
]);

/** @param list<mixed> $enabledModules */
function hr2000VerifiedProfile(
    Hr2000CompanyAxis $companyAxis = Hr2000CompanyAxis::OnePerPlatformCompany,
    Hr2000Transport $transport = Hr2000Transport::FileExchange,
    array $enabledModules = ['customer-confirmed-module'],
    ?string $securityApprovalReference = 'approval:data-processing',
): Hr2000DeploymentProfile {
    return new Hr2000DeploymentProfile(
        product: 'customer-confirmed-product',
        version: 'customer-confirmed-version',
        hostingMode: Hr2000HostingMode::Hosted,
        enabledModules: $enabledModules,
        transport: $transport,
        companyAxis: $companyAxis,
        vendorSupportReference: 'approval:vendor-support',
        fieldMappingReference: 'approval:field-mapping',
        securityApprovalReference: $securityApprovalReference,
        timeZone: 'Asia/Kuala_Lumpur',
        encoding: 'customer-confirmed-encoding',
    );
}
