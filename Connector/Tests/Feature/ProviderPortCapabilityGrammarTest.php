<?php

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityKey;
use App\Base\Authz\Capability\CapabilityRegistry;
use App\Domains\PeopleConnector\Connector\Data\ProviderPortAuthorization;
use App\Domains\PeopleConnector\Connector\Enums\PeopleCapability;

/**
 * Provider-port permissions have to survive the platform's capability grammar.
 *
 * They did not: every one of them was dropped by CapabilityCatalog and denied
 * at runtime, so the whole provider authorization surface was inert. It failed
 * closed, so nothing was exposed — and nothing was reachable either.
 *
 * Two faults, both in the key rather than in the grammar. The direction sat in
 * the middle, and the action is the last segment, so "read" was never read.
 * And the capability's own value carries underscores, which the grammar does
 * not accept at all.
 */
it('builds every provider-port permission in the platform grammar', function (PeopleCapability $capability, string $direction): void {
    $permission = ProviderPortAuthorization::permissionFor($capability, $direction);

    expect(CapabilityKey::isValid($permission))->toBeTrue()
        ->and(CapabilityKey::parse($permission)['action'])->toBe($direction)
        ->and($permission)->not->toContain('_');
})->with(fn () => array_merge(...array_map(
    static fn (PeopleCapability $capability): array => [
        [$capability, 'read'],
        [$capability, 'write'],
    ],
    PeopleCapability::cases(),
)));

it('keeps a distinct permission per capability and direction', function (): void {
    $permissions = [];

    foreach (PeopleCapability::cases() as $capability) {
        foreach (['read', 'write'] as $direction) {
            $permissions[] = ProviderPortAuthorization::permissionFor($capability, $direction);
        }
    }

    // Normalising the key must not collapse two capabilities into one gate.
    // No pair of the twelve cases collides today even with the separator
    // deleted, so this case does not fail for that reason now — it is here so
    // that a thirteenth case which would collide cannot be added quietly.
    expect($permissions)->toHaveCount(count(PeopleCapability::cases()) * 2)
        ->and(array_unique($permissions))->toHaveCount(count($permissions));
});

it('contributes no rejected capability to a composed catalog', function (): void {
    /** @var array<string, mixed> $authzConfig */
    $authzConfig = config('authz');

    $catalog = CapabilityCatalog::fromConfig($authzConfig);
    $considered = array_filter(
        $authzConfig['capabilities'] ?? [],
        static fn (string $capability): bool => str_starts_with($capability, 'people-connector.'),
    );
    $catalog->validate();

    $rejected = array_filter(
        array_keys($catalog->rejected()),
        static fn (string $capability): bool => str_starts_with($capability, 'people-connector.'),
    );

    // Asserting "no Connector key was rejected" passes trivially where no
    // Connector key was loaded — belimbing gitignores the mounts, so a catalog
    // resolved there contains none of these at all. Counting what was
    // considered is what stops this test from passing by finding nothing.
    expect($considered)->not->toBeEmpty()
        ->and($rejected)->toBe([]);

    $registry = CapabilityRegistry::fromCatalog($catalog);

    expect($registry->has(ProviderPortAuthorization::permissionFor(PeopleCapability::EmployeeDirectory, 'read')))
        ->toBeTrue();
});
